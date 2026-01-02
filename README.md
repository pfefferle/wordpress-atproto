# AT Protocol

- Contributors: pfefferle
- Tags: atproto, bluesky, federation, fediverse, decentralized
- Requires at least: 6.0
- Tested up to: 6.7
- Stable tag: 0.1.0
- Requires PHP: 8.0
- License: GPLv2 or later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WordPress site into a federated AT Protocol node.

## Description

This plugin implements the AT Protocol specification, turning your WordPress site into a Personal Data Server (PDS). Your site becomes a first-class citizen in the decentralized social web, with its own DID (Decentralized Identifier) and cryptographic identity.

**Features:**

* **did:web Identity**: Your site gets a decentralized identifier based on your domain (e.g., `did:web:example.com`)
* **DID Document**: Automatically served at `/.well-known/did.json`
* **XRPC Endpoints**: Full implementation of AT Protocol's RPC system
* **Content Federation**: Posts and comments are transformed into AT Protocol records
* **Rich Text Support**: Automatic extraction of mentions, links, and hashtags (facets)
* **Media Support**: Images and attachments are stored as blobs with proper CID references
* **Bidirectional Sync**: Receive interactions from the network via polling
* **Cryptographic Signatures**: All commits are signed with P-256 keys

**How It Works:**

*Outgoing (WordPress → AT Protocol):*

1. You publish a post in WordPress
2. The plugin transforms it into an `app.bsky.feed.post` record
3. Rich text facets (links, mentions, hashtags) are extracted
4. Images are uploaded as blobs
5. The record is signed and stored in your repository

*Incoming (AT Protocol → WordPress):*

1. The plugin polls subscribed DIDs for new content
2. Replies to your posts become WordPress comments
3. Likes and reposts are tracked as comment meta

## Frequently Asked Questions

### Does this replace Bluesky?

No. This plugin makes your WordPress site a node in the AT Protocol network. You can interact with Bluesky users, but your content lives on your own server.

### Do I need a Bluesky account?

No. Your WordPress site becomes its own identity in the network with its own DID and handle.

### Will this work on shared hosting?

Yes. The plugin uses polling instead of WebSockets for incoming content, making it compatible with standard WordPress hosting.

### How do Bluesky users find my content?

They can follow your handle (your domain name) or discover your posts through replies and interactions.

### Is my private key secure?

The private key is stored in the WordPress database (wp_options). For production use, consider using environment variables or a secrets manager.

### What XRPC endpoints are supported?

The plugin implements:

* `com.atproto.identity.resolveHandle`
* `com.atproto.server.describeServer`
* `com.atproto.repo.describeRepo`
* `com.atproto.repo.getRecord`
* `com.atproto.repo.listRecords`
* `com.atproto.repo.createRecord`
* `com.atproto.repo.putRecord`
* `com.atproto.repo.deleteRecord`
* `com.atproto.repo.uploadBlob`
* `com.atproto.sync.getRepo`
* `com.atproto.sync.getBlob`

## Changelog

Project and support maintained on GitHub at [pfefferle/wordpress-atproto](https://github.com/pfefferle/wordpress-atproto).

### 0.1.0

* Initial release
* did:web identity support
* DID document generation
* XRPC endpoint implementation
* Post and comment federation
* Blob storage for media
* P-256 cryptographic signatures
* Polling-based relay subscription

## Installation

Follow the normal instructions for [installing WordPress plugins](https://codex.wordpress.org/Managing_Plugins#Installing_Plugins).

### Automatic Plugin Installation

To add a WordPress Plugin using the [built-in plugin installer](https://codex.wordpress.org/Administration_Screens#Add_New_Plugins):

1. Go to [Plugins](https://codex.wordpress.org/Administration_Screens#Plugins) > [Add New](https://codex.wordpress.org/Plugins_Add_New_Screen).
1. Type "`atproto`" into the **Search Plugins** box.
1. Find the WordPress Plugin you wish to install.
    1. Click **Details** for more information about the Plugin and instructions you may wish to print or save to help setup the Plugin.
    1. Click **Install Now** to install the WordPress Plugin.
1. The resulting installation screen will list the installation as successful or note any problems during the install.
1. If successful, click **Activate Plugin** to activate it, or **Return to Plugin Installer** for further actions.

### Manual Plugin Installation

There are a few cases when manually installing a WordPress Plugin is appropriate.

* If you wish to control the placement and the process of installing a WordPress Plugin.
* If your server does not permit automatic installation of a WordPress Plugin.
* If you want to try the [latest development version](https://github.com/pfefferle/wordpress-atproto).

Installation of a WordPress Plugin manually requires FTP familiarity and the awareness that you may put your site at risk if you install a WordPress Plugin incompatible with the current version or from an unreliable source.

Backup your site completely before proceeding.

To install a WordPress Plugin manually:

* Download your WordPress Plugin to your desktop.
    * Download from [GitHub](https://github.com/pfefferle/wordpress-atproto/releases)
* If downloaded as a zip archive, extract the Plugin folder to your desktop.
* With your FTP program, upload the Plugin folder to the `wp-content/plugins` folder in your WordPress directory online.
* Go to [Plugins screen](https://codex.wordpress.org/Administration_Screens#Plugins) and find the newly uploaded Plugin in the list.
* Click **Activate** to activate it.

### Configuration

After activation, visit **Settings → AT Protocol** to:

1. **Generate Keys**: Create your cryptographic keypair (done automatically on first activation)
2. **Verify DID Document**: Check that `/.well-known/did.json` is accessible
3. **Configure Federation**: Set relay URL and manage subscriptions

Ensure your site uses pretty permalinks (Settings → Permalinks) for the `.well-known/did.json` endpoint to work correctly.

## Upgrade Notice

### 0.1.0

Initial release.
