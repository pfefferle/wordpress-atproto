#!/usr/bin/env php
<?php
/**
 * Minimal AT Protocol Firehose (subscribeRepos) WebSocket Server.
 *
 * EXPERIMENTAL - Minimal implementation for relay sync.
 *
 * Usage:
 *   1. composer require cboden/ratchet
 *   2. php bin/firehose.php \
 *        --db-host=localhost \
 *        --db-name=wordpress \
 *        --db-user=root \
 *        --db-pass=secret \
 *        --home-url=https://notiz.blog \
 *        --port=8080
 *
 * Nginx config:
 *   location = /xrpc/com.atproto.sync.subscribeRepos {
 *       proxy_pass http://127.0.0.1:8080;
 *       proxy_http_version 1.1;
 *       proxy_set_header Upgrade $http_upgrade;
 *       proxy_set_header Connection "upgrade";
 *       proxy_read_timeout 86400;
 *   }
 *
 * @package ATProto
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use ATProto\Repository\CBOR;

// Parse arguments.
$options = getopt( '', array( 'db-host:', 'db-name:', 'db-user:', 'db-pass:', 'db-prefix:', 'home-url:', 'port:' ) );

$db_host   = $options['db-host'] ?? 'localhost';
$db_name   = $options['db-name'] ?? null;
$db_user   = $options['db-user'] ?? null;
$db_pass   = $options['db-pass'] ?? '';
$db_prefix = $options['db-prefix'] ?? 'wp_';
$home_url  = $options['home-url'] ?? null;
$port      = (int) ( $options['port'] ?? 8080 );

if ( ! $db_name || ! $db_user || ! $home_url ) {
	echo "Usage: php firehose.php --db-name=NAME --db-user=USER --home-url=URL [options]\n";
	echo "Required:\n";
	echo "  --db-name=NAME    WordPress database name\n";
	echo "  --db-user=USER    Database username\n";
	echo "  --home-url=URL    Site URL (e.g., https://notiz.blog)\n";
	echo "Optional:\n";
	echo "  --db-host=HOST    Database host (default: localhost)\n";
	echo "  --db-pass=PASS    Database password\n";
	echo "  --db-prefix=PRE   Table prefix (default: wp_)\n";
	echo "  --port=PORT       WebSocket port (default: 8080)\n";
	exit( 1 );
}

// Check for Ratchet.
if ( ! class_exists( 'Ratchet\Server\IoServer' ) ) {
	echo "Error: Ratchet not installed.\n";
	echo "Run: composer require cboden/ratchet\n";
	exit( 1 );
}

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// Compute DID from home URL.
$parsed  = parse_url( $home_url );
$did     = 'did:web:' . $parsed['host'];
if ( isset( $parsed['port'] ) ) {
	$did .= '%3A' . $parsed['port'];
}

echo "AT Protocol Firehose Server\n";
echo "DID: $did\n";
echo "Port: $port\n\n";

/**
 * Database connection.
 */
class Database {
	private static $pdo;
	private static $prefix;

	public static function init( $host, $name, $user, $pass, $prefix ) {
		self::$prefix = $prefix;
		self::$pdo = new PDO(
			"mysql:host=$host;dbname=$name;charset=utf8mb4",
			$user,
			$pass,
			array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
		);
	}

	public static function query( $sql, $params = array() ) {
		$sql  = str_replace( '{prefix}', self::$prefix, $sql );
		$stmt = self::$pdo->prepare( $sql );
		$stmt->execute( $params );
		return $stmt->fetchAll( PDO::FETCH_OBJ );
	}
}

/**
 * Firehose handler.
 */
class Firehose implements MessageComponentInterface {
	protected $clients;
	protected $did;

	public function __construct( $did ) {
		$this->clients = new \SplObjectStorage();
		$this->did     = $did;
	}

	public function onOpen( ConnectionInterface $conn ) {
		$this->clients->attach( $conn );

		$query  = $conn->httpRequest->getUri()->getQuery();
		parse_str( $query, $params );
		$cursor = isset( $params['cursor'] ) ? (int) $params['cursor'] : 0;

		echo "[{$conn->resourceId}] Connected, cursor: $cursor\n";

		$this->sendCommits( $conn, $cursor );
	}

	protected function sendCommits( ConnectionInterface $conn, int $cursor ) {
		// Get posts with AT Protocol records.
		$posts = Database::query(
			"SELECT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_date_gmt,
			        pm_tid.meta_value as tid, pm_cid.meta_value as cid
			FROM {prefix}posts p
			INNER JOIN {prefix}postmeta pm_tid ON p.ID = pm_tid.post_id AND pm_tid.meta_key = '_atproto_tid'
			LEFT JOIN {prefix}postmeta pm_cid ON p.ID = pm_cid.post_id AND pm_cid.meta_key = '_atproto_cid'
			WHERE p.post_status = 'publish'
			AND p.ID > ?
			ORDER BY p.ID ASC
			LIMIT 50",
			array( $cursor )
		);

		foreach ( $posts as $post ) {
			$frame = $this->buildCommitFrame( $post );
			$conn->send( $frame );
		}

		echo "[{$conn->resourceId}] Sent " . count( $posts ) . " commits\n";
	}

	protected function buildCommitFrame( $post ) {
		// Frame header.
		$header = array(
			'op' => 1,
			't'  => '#commit',
		);

		// Commit body.
		$body = array(
			'seq'    => (int) $post->ID,
			'rebase' => false,
			'tooBig' => false,
			'repo'   => $this->did,
			'rev'    => $post->tid,
			'since'  => null,
			'blocks' => '', // Empty CAR - relay will fetch via getRepo if needed.
			'ops'    => array(
				array(
					'action' => 'create',
					'path'   => 'app.bsky.feed.post/' . $post->tid,
					'cid'    => null,
				),
			),
			'blobs'  => array(),
			'time'   => gmdate( 'c', strtotime( $post->post_date_gmt ) ),
		);

		if ( $post->cid ) {
			$body['commit'] = array( '$link' => $post->cid );
			$body['ops'][0]['cid'] = array( '$link' => $post->cid );
		}

		// Encode header and body as CBOR.
		$header_cbor = CBOR::encode( $header );
		$body_cbor   = CBOR::encode( $body );

		// Frame: varint(header_len) + header + body
		return self::varint( strlen( $header_cbor ) ) . $header_cbor . $body_cbor;
	}

	protected static function varint( $n ) {
		$bytes = '';
		while ( $n >= 0x80 ) {
			$bytes .= chr( ( $n & 0x7F ) | 0x80 );
			$n >>= 7;
		}
		return $bytes . chr( $n );
	}

	public function onMessage( ConnectionInterface $from, $msg ) {
		// subscribeRepos is server-push only.
	}

	public function onClose( ConnectionInterface $conn ) {
		$this->clients->detach( $conn );
		echo "[{$conn->resourceId}] Disconnected\n";
	}

	public function onError( ConnectionInterface $conn, \Exception $e ) {
		echo "[{$conn->resourceId}] Error: {$e->getMessage()}\n";
		$conn->close();
	}
}

// Initialize database.
try {
	Database::init( $db_host, $db_name, $db_user, $db_pass, $db_prefix );
	echo "Database connected.\n";
} catch ( Exception $e ) {
	echo "Database error: {$e->getMessage()}\n";
	exit( 1 );
}

// Start server.
echo "Starting WebSocket server on 0.0.0.0:$port...\n";

$server = IoServer::factory(
	new HttpServer(
		new WsServer(
			new Firehose( $did )
		)
	),
	$port,
	'0.0.0.0' // Required for Uberspace and similar hosts.
);

$server->run();
