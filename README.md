# WP Data Sync SSO

This plugin relies on the [WP OAuth Server](https://wordpress.org/plugins/oauth2-provider/) plugin.

## Client Setup

1. Add new client 
2. Add username for title
3. Add redirect url
4. Save

### Redirect URL

`https://remote-website.com/wp-json/wpds-sso/login`

Make sure to replace`remote-website.com`with your remote website URL.

## Remote Website Configuration

1. Install and activate our SSO plugin on the remote website
2. Navigate to Settings > WP Data Sync > SSO
3. Add server URL: this is the URL where the Oauth Server is located
4. Add client ID and client secret.
5. Save changes