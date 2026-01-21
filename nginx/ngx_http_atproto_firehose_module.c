/**
 * nginx AT Protocol Firehose Module
 *
 * Provides WebSocket endpoint for AT Protocol subscribeRepos and broadcasts
 * events from PHP responses via X-ATProto-Event header interception.
 *
 * @author WordPress ATProto Plugin
 */

#include <ngx_config.h>
#include <ngx_core.h>
#include <ngx_http.h>
#include <ngx_sha1.h>

#define ATPROTO_WS_GUID "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
#define ATPROTO_DEFAULT_PING_INTERVAL 25000

/* WebSocket frame opcodes */
#define WS_OPCODE_CONTINUATION 0x00
#define WS_OPCODE_TEXT         0x01
#define WS_OPCODE_BINARY       0x02
#define WS_OPCODE_CLOSE        0x08
#define WS_OPCODE_PING         0x09
#define WS_OPCODE_PONG         0x0A

/* Forward declarations */
typedef struct ngx_http_atproto_firehose_main_conf_s ngx_http_atproto_firehose_main_conf_t;
typedef struct ngx_http_atproto_firehose_loc_conf_s ngx_http_atproto_firehose_loc_conf_t;
typedef struct atproto_ws_client_s atproto_ws_client_t;

/* WebSocket client structure */
struct atproto_ws_client_s {
    ngx_queue_t               queue;
    ngx_connection_t         *connection;
    ngx_http_request_t       *request;
    ngx_event_t               ping_event;
    ngx_pool_t               *pool;
    unsigned                  closing:1;
};

/* Main configuration */
struct ngx_http_atproto_firehose_main_conf_s {
    ngx_flag_t                enable;
    ngx_msec_t                ping_interval;
    ngx_queue_t               clients;
    ngx_uint_t                client_count;
    ngx_pool_t               *pool;
};

/* Location configuration */
struct ngx_http_atproto_firehose_loc_conf_s {
    ngx_flag_t                endpoint;
};

/* Module context */
static ngx_http_module_t ngx_http_atproto_firehose_module_ctx;

/* Function prototypes */
static ngx_int_t ngx_http_atproto_firehose_init(ngx_conf_t *cf);
static void *ngx_http_atproto_firehose_create_main_conf(ngx_conf_t *cf);
static char *ngx_http_atproto_firehose_init_main_conf(ngx_conf_t *cf, void *conf);
static void *ngx_http_atproto_firehose_create_loc_conf(ngx_conf_t *cf);
static char *ngx_http_atproto_firehose_merge_loc_conf(ngx_conf_t *cf, void *parent, void *child);
static char *ngx_http_atproto_firehose_endpoint(ngx_conf_t *cf, ngx_command_t *cmd, void *conf);
static ngx_int_t ngx_http_atproto_firehose_handler(ngx_http_request_t *r);
static ngx_int_t ngx_http_atproto_firehose_header_filter(ngx_http_request_t *r);
static void ngx_http_atproto_firehose_broadcast(ngx_http_atproto_firehose_main_conf_t *mcf,
    u_char *data, size_t len);
static void ngx_http_atproto_firehose_ping_handler(ngx_event_t *ev);
static void ngx_http_atproto_firehose_read_handler(ngx_event_t *ev);
static void ngx_http_atproto_firehose_write_handler(ngx_event_t *ev);
static void ngx_http_atproto_firehose_close_client(atproto_ws_client_t *client);
static ngx_int_t ngx_http_atproto_firehose_send_frame(atproto_ws_client_t *client,
    u_char opcode, u_char *data, size_t len);
static ssize_t ngx_http_atproto_firehose_base64_decode(u_char *dst, size_t dlen,
    u_char *src, size_t slen);

/* Commands */
static ngx_command_t ngx_http_atproto_firehose_commands[] = {

    { ngx_string("atproto_firehose"),
      NGX_HTTP_MAIN_CONF|NGX_CONF_FLAG,
      ngx_conf_set_flag_slot,
      NGX_HTTP_MAIN_CONF_OFFSET,
      offsetof(ngx_http_atproto_firehose_main_conf_t, enable),
      NULL },

    { ngx_string("atproto_firehose_ping_interval"),
      NGX_HTTP_MAIN_CONF|NGX_CONF_TAKE1,
      ngx_conf_set_msec_slot,
      NGX_HTTP_MAIN_CONF_OFFSET,
      offsetof(ngx_http_atproto_firehose_main_conf_t, ping_interval),
      NULL },

    { ngx_string("atproto_firehose_endpoint"),
      NGX_HTTP_LOC_CONF|NGX_CONF_NOARGS,
      ngx_http_atproto_firehose_endpoint,
      NGX_HTTP_LOC_CONF_OFFSET,
      0,
      NULL },

    ngx_null_command
};

/* Module context */
static ngx_http_module_t ngx_http_atproto_firehose_module_ctx = {
    NULL,                                         /* preconfiguration */
    ngx_http_atproto_firehose_init,              /* postconfiguration */
    ngx_http_atproto_firehose_create_main_conf,  /* create main configuration */
    ngx_http_atproto_firehose_init_main_conf,    /* init main configuration */
    NULL,                                         /* create server configuration */
    NULL,                                         /* merge server configuration */
    ngx_http_atproto_firehose_create_loc_conf,   /* create location configuration */
    ngx_http_atproto_firehose_merge_loc_conf     /* merge location configuration */
};

/* Module definition */
ngx_module_t ngx_http_atproto_firehose_module = {
    NGX_MODULE_V1,
    &ngx_http_atproto_firehose_module_ctx,       /* module context */
    ngx_http_atproto_firehose_commands,          /* module directives */
    NGX_HTTP_MODULE,                              /* module type */
    NULL,                                         /* init master */
    NULL,                                         /* init module */
    NULL,                                         /* init process */
    NULL,                                         /* init thread */
    NULL,                                         /* exit thread */
    NULL,                                         /* exit process */
    NULL,                                         /* exit master */
    NGX_MODULE_V1_PADDING
};

/* Original header filter */
static ngx_http_output_header_filter_pt ngx_http_next_header_filter;

/**
 * Create main configuration.
 */
static void *
ngx_http_atproto_firehose_create_main_conf(ngx_conf_t *cf)
{
    ngx_http_atproto_firehose_main_conf_t *mcf;

    mcf = ngx_pcalloc(cf->pool, sizeof(ngx_http_atproto_firehose_main_conf_t));
    if (mcf == NULL) {
        return NULL;
    }

    mcf->enable = NGX_CONF_UNSET;
    mcf->ping_interval = NGX_CONF_UNSET_MSEC;
    mcf->client_count = 0;
    mcf->pool = cf->pool;

    ngx_queue_init(&mcf->clients);

    return mcf;
}

/**
 * Initialize main configuration.
 */
static char *
ngx_http_atproto_firehose_init_main_conf(ngx_conf_t *cf, void *conf)
{
    ngx_http_atproto_firehose_main_conf_t *mcf = conf;

    if (mcf->enable == NGX_CONF_UNSET) {
        mcf->enable = 0;
    }

    if (mcf->ping_interval == NGX_CONF_UNSET_MSEC) {
        mcf->ping_interval = ATPROTO_DEFAULT_PING_INTERVAL;
    }

    return NGX_CONF_OK;
}

/**
 * Create location configuration.
 */
static void *
ngx_http_atproto_firehose_create_loc_conf(ngx_conf_t *cf)
{
    ngx_http_atproto_firehose_loc_conf_t *lcf;

    lcf = ngx_pcalloc(cf->pool, sizeof(ngx_http_atproto_firehose_loc_conf_t));
    if (lcf == NULL) {
        return NULL;
    }

    lcf->endpoint = NGX_CONF_UNSET;

    return lcf;
}

/**
 * Merge location configuration.
 */
static char *
ngx_http_atproto_firehose_merge_loc_conf(ngx_conf_t *cf, void *parent, void *child)
{
    ngx_http_atproto_firehose_loc_conf_t *prev = parent;
    ngx_http_atproto_firehose_loc_conf_t *conf = child;

    ngx_conf_merge_value(conf->endpoint, prev->endpoint, 0);

    return NGX_CONF_OK;
}

/**
 * Configure endpoint location.
 */
static char *
ngx_http_atproto_firehose_endpoint(ngx_conf_t *cf, ngx_command_t *cmd, void *conf)
{
    ngx_http_atproto_firehose_loc_conf_t *lcf = conf;
    ngx_http_core_loc_conf_t *clcf;

    lcf->endpoint = 1;

    clcf = ngx_http_conf_get_module_loc_conf(cf, ngx_http_core_module);
    clcf->handler = ngx_http_atproto_firehose_handler;

    return NGX_CONF_OK;
}

/**
 * Initialize module - install header filter.
 */
static ngx_int_t
ngx_http_atproto_firehose_init(ngx_conf_t *cf)
{
    ngx_http_next_header_filter = ngx_http_top_header_filter;
    ngx_http_top_header_filter = ngx_http_atproto_firehose_header_filter;

    return NGX_OK;
}

/**
 * Compute WebSocket accept key from client key.
 */
static ngx_int_t
ngx_http_atproto_firehose_ws_accept_key(ngx_pool_t *pool, ngx_str_t *key,
    ngx_str_t *accept)
{
    ngx_sha1_t   sha1;
    u_char       digest[20];
    u_char       concat[60 + sizeof(ATPROTO_WS_GUID)];
    size_t       len;

    if (key->len > 60) {
        return NGX_ERROR;
    }

    ngx_memcpy(concat, key->data, key->len);
    ngx_memcpy(concat + key->len, ATPROTO_WS_GUID, sizeof(ATPROTO_WS_GUID) - 1);
    len = key->len + sizeof(ATPROTO_WS_GUID) - 1;

    ngx_sha1_init(&sha1);
    ngx_sha1_update(&sha1, concat, len);
    ngx_sha1_final(digest, &sha1);

    accept->len = ngx_base64_encoded_length(20);
    accept->data = ngx_pnalloc(pool, accept->len + 1);
    if (accept->data == NULL) {
        return NGX_ERROR;
    }

    ngx_encode_base64(accept, &(ngx_str_t){20, digest});

    return NGX_OK;
}

/**
 * Handle WebSocket endpoint requests.
 */
static ngx_int_t
ngx_http_atproto_firehose_handler(ngx_http_request_t *r)
{
    ngx_http_atproto_firehose_main_conf_t *mcf;
    ngx_http_atproto_firehose_loc_conf_t  *lcf;
    atproto_ws_client_t                   *client;
    ngx_table_elt_t                       *h;
    ngx_str_t                              ws_key, ws_accept;
    ngx_buf_t                             *b;
    ngx_chain_t                            out;
    ngx_int_t                              rc;

    lcf = ngx_http_get_module_loc_conf(r, ngx_http_atproto_firehose_module);

    if (!lcf->endpoint) {
        return NGX_DECLINED;
    }

    mcf = ngx_http_get_module_main_conf(r, ngx_http_atproto_firehose_module);

    if (!mcf->enable) {
        return NGX_HTTP_SERVICE_UNAVAILABLE;
    }

    /* Check for WebSocket upgrade */
    if (r->method != NGX_HTTP_GET) {
        return NGX_HTTP_NOT_ALLOWED;
    }

    /* Find Sec-WebSocket-Key header */
    ws_key.len = 0;
    ws_key.data = NULL;

    ngx_list_part_t *part = &r->headers_in.headers.part;
    ngx_table_elt_t *header = part->elts;
    ngx_uint_t i;

    for (i = 0; /* void */; i++) {
        if (i >= part->nelts) {
            if (part->next == NULL) {
                break;
            }
            part = part->next;
            header = part->elts;
            i = 0;
        }

        if (header[i].key.len == sizeof("Sec-WebSocket-Key") - 1
            && ngx_strncasecmp(header[i].key.data,
                (u_char *)"Sec-WebSocket-Key",
                sizeof("Sec-WebSocket-Key") - 1) == 0)
        {
            ws_key = header[i].value;
            break;
        }
    }

    if (ws_key.len == 0) {
        ngx_log_error(NGX_LOG_ERR, r->connection->log, 0,
            "atproto firehose: missing Sec-WebSocket-Key header");
        return NGX_HTTP_BAD_REQUEST;
    }

    /* Compute accept key */
    if (ngx_http_atproto_firehose_ws_accept_key(r->pool, &ws_key, &ws_accept)
        != NGX_OK)
    {
        return NGX_HTTP_INTERNAL_SERVER_ERROR;
    }

    /* Create client structure */
    client = ngx_pcalloc(r->pool, sizeof(atproto_ws_client_t));
    if (client == NULL) {
        return NGX_HTTP_INTERNAL_SERVER_ERROR;
    }

    client->connection = r->connection;
    client->request = r;
    client->pool = r->pool;
    client->closing = 0;

    /* Add to client list */
    ngx_queue_insert_tail(&mcf->clients, &client->queue);
    mcf->client_count++;

    ngx_log_error(NGX_LOG_INFO, r->connection->log, 0,
        "atproto firehose: client connected, total=%ui", mcf->client_count);

    /* Send WebSocket handshake response */
    r->headers_out.status = NGX_HTTP_SWITCHING_PROTOCOLS;

    h = ngx_list_push(&r->headers_out.headers);
    if (h == NULL) {
        return NGX_HTTP_INTERNAL_SERVER_ERROR;
    }
    h->hash = 1;
    ngx_str_set(&h->key, "Upgrade");
    ngx_str_set(&h->value, "websocket");

    h = ngx_list_push(&r->headers_out.headers);
    if (h == NULL) {
        return NGX_HTTP_INTERNAL_SERVER_ERROR;
    }
    h->hash = 1;
    ngx_str_set(&h->key, "Connection");
    ngx_str_set(&h->value, "Upgrade");

    h = ngx_list_push(&r->headers_out.headers);
    if (h == NULL) {
        return NGX_HTTP_INTERNAL_SERVER_ERROR;
    }
    h->hash = 1;
    ngx_str_set(&h->key, "Sec-WebSocket-Accept");
    h->value = ws_accept;

    r->keepalive = 0;

    rc = ngx_http_send_header(r);
    if (rc == NGX_ERROR || rc > NGX_OK) {
        return rc;
    }

    /* Send empty body to complete handshake */
    b = ngx_calloc_buf(r->pool);
    if (b == NULL) {
        return NGX_HTTP_INTERNAL_SERVER_ERROR;
    }

    b->last_buf = 1;
    b->last_in_chain = 1;

    out.buf = b;
    out.next = NULL;

    rc = ngx_http_output_filter(r, &out);
    if (rc != NGX_OK) {
        return rc;
    }

    /* Set up connection for WebSocket communication */
    r->connection->read->handler = ngx_http_atproto_firehose_read_handler;
    r->connection->write->handler = ngx_http_atproto_firehose_write_handler;
    r->connection->data = client;

    /* Set up ping timer */
    client->ping_event.handler = ngx_http_atproto_firehose_ping_handler;
    client->ping_event.data = client;
    client->ping_event.log = r->connection->log;

    ngx_add_timer(&client->ping_event, mcf->ping_interval);

    /* Increase reference count to keep request alive */
    r->count++;

    return NGX_DONE;
}

/**
 * Handle incoming WebSocket data.
 */
static void
ngx_http_atproto_firehose_read_handler(ngx_event_t *ev)
{
    ngx_connection_t    *c;
    atproto_ws_client_t *client;
    u_char               buf[1024];
    ssize_t              n;
    u_char               opcode;

    c = ev->data;
    client = c->data;

    if (client->closing) {
        return;
    }

    n = c->recv(c, buf, sizeof(buf));

    if (n == NGX_AGAIN) {
        if (ngx_handle_read_event(ev, 0) != NGX_OK) {
            ngx_http_atproto_firehose_close_client(client);
        }
        return;
    }

    if (n <= 0) {
        ngx_http_atproto_firehose_close_client(client);
        return;
    }

    /* Parse WebSocket frame (minimal parsing) */
    if (n < 2) {
        return;
    }

    opcode = buf[0] & 0x0F;

    switch (opcode) {
    case WS_OPCODE_CLOSE:
        ngx_log_error(NGX_LOG_INFO, c->log, 0,
            "atproto firehose: client sent close frame");
        ngx_http_atproto_firehose_close_client(client);
        break;

    case WS_OPCODE_PING:
        /* Respond with pong */
        ngx_http_atproto_firehose_send_frame(client, WS_OPCODE_PONG, NULL, 0);
        break;

    case WS_OPCODE_PONG:
        /* Ignore pong responses */
        break;

    default:
        /* Ignore other frames - this is a one-way stream */
        break;
    }

    if (ngx_handle_read_event(ev, 0) != NGX_OK) {
        ngx_http_atproto_firehose_close_client(client);
    }
}

/**
 * Handle write events.
 */
static void
ngx_http_atproto_firehose_write_handler(ngx_event_t *ev)
{
    ngx_connection_t    *c;
    atproto_ws_client_t *client;

    c = ev->data;
    client = c->data;

    if (client->closing) {
        return;
    }

    if (ngx_handle_write_event(ev, 0) != NGX_OK) {
        ngx_http_atproto_firehose_close_client(client);
    }
}

/**
 * Send ping frame to keep connection alive.
 */
static void
ngx_http_atproto_firehose_ping_handler(ngx_event_t *ev)
{
    atproto_ws_client_t                   *client;
    ngx_http_atproto_firehose_main_conf_t *mcf;

    client = ev->data;

    if (client->closing) {
        return;
    }

    ngx_http_atproto_firehose_send_frame(client, WS_OPCODE_PING, NULL, 0);

    mcf = ngx_http_get_module_main_conf(client->request,
        ngx_http_atproto_firehose_module);

    ngx_add_timer(&client->ping_event, mcf->ping_interval);
}

/**
 * Send a WebSocket frame.
 */
static ngx_int_t
ngx_http_atproto_firehose_send_frame(atproto_ws_client_t *client,
    u_char opcode, u_char *data, size_t len)
{
    u_char   header[10];
    size_t   header_len;
    ssize_t  n;

    if (client->closing || client->connection->error) {
        return NGX_ERROR;
    }

    /* Build frame header */
    header[0] = 0x80 | opcode;  /* FIN + opcode */

    if (len < 126) {
        header[1] = (u_char)len;
        header_len = 2;
    } else if (len < 65536) {
        header[1] = 126;
        header[2] = (u_char)(len >> 8);
        header[3] = (u_char)(len & 0xFF);
        header_len = 4;
    } else {
        header[1] = 127;
        header[2] = 0;
        header[3] = 0;
        header[4] = 0;
        header[5] = 0;
        header[6] = (u_char)(len >> 24);
        header[7] = (u_char)(len >> 16);
        header[8] = (u_char)(len >> 8);
        header[9] = (u_char)(len & 0xFF);
        header_len = 10;
    }

    /* Send header */
    n = client->connection->send(client->connection, header, header_len);
    if (n != (ssize_t)header_len) {
        return NGX_ERROR;
    }

    /* Send payload if present */
    if (len > 0 && data != NULL) {
        n = client->connection->send(client->connection, data, len);
        if (n != (ssize_t)len) {
            return NGX_ERROR;
        }
    }

    return NGX_OK;
}

/**
 * Close a WebSocket client connection.
 */
static void
ngx_http_atproto_firehose_close_client(atproto_ws_client_t *client)
{
    ngx_http_atproto_firehose_main_conf_t *mcf;

    if (client->closing) {
        return;
    }

    client->closing = 1;

    /* Cancel ping timer */
    if (client->ping_event.timer_set) {
        ngx_del_timer(&client->ping_event);
    }

    /* Remove from client list */
    mcf = ngx_http_get_module_main_conf(client->request,
        ngx_http_atproto_firehose_module);

    ngx_queue_remove(&client->queue);
    mcf->client_count--;

    ngx_log_error(NGX_LOG_INFO, client->connection->log, 0,
        "atproto firehose: client disconnected, remaining=%ui",
        mcf->client_count);

    /* Send close frame */
    ngx_http_atproto_firehose_send_frame(client, WS_OPCODE_CLOSE, NULL, 0);

    /* Finalize request */
    ngx_http_finalize_request(client->request, NGX_DONE);
}

/**
 * Base64 decode helper.
 */
static ssize_t
ngx_http_atproto_firehose_base64_decode(u_char *dst, size_t dlen,
    u_char *src, size_t slen)
{
    ngx_str_t encoded, decoded;

    encoded.data = src;
    encoded.len = slen;

    decoded.data = dst;
    decoded.len = dlen;

    if (ngx_decode_base64(&decoded, &encoded) != NGX_OK) {
        return -1;
    }

    return decoded.len;
}

/**
 * Broadcast data to all connected WebSocket clients.
 */
static void
ngx_http_atproto_firehose_broadcast(ngx_http_atproto_firehose_main_conf_t *mcf,
    u_char *data, size_t len)
{
    ngx_queue_t         *q, *next;
    atproto_ws_client_t *client;
    ngx_uint_t           sent = 0;

    for (q = ngx_queue_head(&mcf->clients);
         q != ngx_queue_sentinel(&mcf->clients);
         q = next)
    {
        next = ngx_queue_next(q);
        client = ngx_queue_data(q, atproto_ws_client_t, queue);

        if (!client->closing) {
            if (ngx_http_atproto_firehose_send_frame(client, WS_OPCODE_BINARY,
                    data, len) == NGX_OK)
            {
                sent++;
            } else {
                ngx_http_atproto_firehose_close_client(client);
            }
        }
    }

    ngx_log_error(NGX_LOG_DEBUG, ngx_cycle->log, 0,
        "atproto firehose: broadcast %uz bytes to %ui clients", len, sent);
}

/**
 * Header filter - intercept X-ATProto-Event headers.
 */
static ngx_int_t
ngx_http_atproto_firehose_header_filter(ngx_http_request_t *r)
{
    ngx_http_atproto_firehose_main_conf_t *mcf;
    ngx_list_part_t                       *part;
    ngx_table_elt_t                       *h;
    ngx_uint_t                             i;
    u_char                                *decoded;
    ssize_t                                decoded_len;
    size_t                                 max_decoded_len;

    mcf = ngx_http_get_module_main_conf(r, ngx_http_atproto_firehose_module);

    if (!mcf->enable) {
        return ngx_http_next_header_filter(r);
    }

    /* Search for X-ATProto-Event header */
    part = &r->headers_out.headers.part;
    h = part->elts;

    for (i = 0; /* void */; i++) {
        if (i >= part->nelts) {
            if (part->next == NULL) {
                break;
            }
            part = part->next;
            h = part->elts;
            i = 0;
        }

        if (h[i].key.len == sizeof("X-ATProto-Event") - 1
            && ngx_strncasecmp(h[i].key.data, (u_char *)"X-ATProto-Event",
                sizeof("X-ATProto-Event") - 1) == 0)
        {
            /* Found the header - decode and broadcast */
            max_decoded_len = ngx_base64_decoded_length(h[i].value.len);
            decoded = ngx_pnalloc(r->pool, max_decoded_len);

            if (decoded != NULL) {
                decoded_len = ngx_http_atproto_firehose_base64_decode(
                    decoded, max_decoded_len, h[i].value.data, h[i].value.len);

                if (decoded_len > 0) {
                    ngx_log_error(NGX_LOG_INFO, r->connection->log, 0,
                        "atproto firehose: broadcasting %z bytes from header",
                        decoded_len);

                    ngx_http_atproto_firehose_broadcast(mcf, decoded,
                        decoded_len);
                }
            }

            /* Strip header from response */
            h[i].hash = 0;
            h[i].key.len = 0;
            h[i].value.len = 0;

            break;
        }
    }

    return ngx_http_next_header_filter(r);
}
