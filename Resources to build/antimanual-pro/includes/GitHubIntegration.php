<?php

namespace Antimanual_Pro;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Antimanual\Embedding;

class GitHubIntegration {
    const OPTION_TOKEN = 'github_access_token';
    const OPTION_USER  = 'github_user';

    public static function get_token(): string {
        $token = atml_option( self::OPTION_TOKEN );
        return is_string( $token ) ? trim( $token ) : '';
    }

    public static function get_user(): array {
        $user = atml_option( self::OPTION_USER );
        return is_array( $user ) ? $user : [];
    }

    public static function set_user( array $user ): void {
        atml_option_save( self::OPTION_USER, $user );
    }

    public static function set_token( string $token ): void {
        atml_option_save( self::OPTION_TOKEN, $token );
    }

    public static function disconnect(): void {
        self::set_token( '' );
        self::set_user( [] );
    }

    public static function get_status(): array {
        $token = self::get_token();

        if ( empty( $token ) ) {
            return [
                'connected' => false,
                'user'      => null,
            ];
        }

        $user = self::get_user();

        if ( empty( $user ) ) {
            $user = self::request_json( '/user', [], $token );

            if ( is_wp_error( $user ) ) {
                return [
                    'connected' => false,
                    'user'      => null,
                    'error'     => $user->get_error_message(),
                ];
            }

            self::set_user( $user );
        }

        return [
            'connected' => true,
            'user'      => [
                'login'      => $user['login'] ?? '',
                'avatar_url' => $user['avatar_url'] ?? '',
                'html_url'   => $user['html_url'] ?? '',
            ],
        ];
    }

    public static function connect( string $token ) {
        $token = trim( $token );

        if ( empty( $token ) ) {
            return new \WP_Error( 'missing_token', __( 'GitHub token is required.', 'antimanual' ) );
        }

        $user = self::request_json( '/user', [], $token );

        if ( is_wp_error( $user ) ) {
            return $user;
        }

        self::set_token( $token );
        self::set_user( $user );

        return [
            'login'      => $user['login'] ?? '',
            'avatar_url' => $user['avatar_url'] ?? '',
            'html_url'   => $user['html_url'] ?? '',
        ];
    }

    public static function list_repos( int $page = 1, int $per_page = 50, string $search = '' ) {
        $token = self::get_token();

        if ( empty( $token ) ) {
            return new \WP_Error( 'not_connected', __( 'GitHub is not connected.', 'antimanual' ) );
        }

        $repos = self::request_json(
            '/user/repos',
            [
                'page'        => max( 1, $page ),
                'per_page'    => max( 1, min( 100, $per_page ) ),
                'sort'        => 'updated',
                'affiliation' => 'owner,collaborator,organization_member',
            ],
            $token
        );

        if ( is_wp_error( $repos ) ) {
            return $repos;
        }

        $search = trim( $search );

        $formatted = array_map( function( $repo ) {
            return [
                'id'             => $repo['id'] ?? 0,
                'name'           => $repo['name'] ?? '',
                'full_name'      => $repo['full_name'] ?? '',
                'html_url'       => $repo['html_url'] ?? '',
                'private'        => (bool) ( $repo['private'] ?? false ),
                'updated_at'     => $repo['updated_at'] ?? '',
                'default_branch' => $repo['default_branch'] ?? 'main',
                'description'    => $repo['description'] ?? '',
                'topics'         => $repo['topics'] ?? [],
            ];
        }, $repos );

        if ( ! empty( $search ) ) {
            $formatted = array_values( array_filter( $formatted, function( $repo ) use ( $search ) {
                $haystack = strtolower( $repo['full_name'] . ' ' . $repo['description'] );
                return strpos( $haystack, strtolower( $search ) ) !== false;
            } ) );
        }

        return [
            'repos'    => $formatted,
            'page'     => $page,
            'per_page' => $per_page,
            'count'    => count( $formatted ),
        ];
    }

    public static function add_repo_to_kb( string $full_name, array $options = [] ) {
        $token = self::get_token();

        if ( empty( $token ) ) {
            return new \WP_Error( 'not_connected', __( 'GitHub is not connected.', 'antimanual' ) );
        }

        $full_name = trim( $full_name );

        if ( empty( $full_name ) ) {
            return new \WP_Error( 'missing_repo', __( 'Repository is required.', 'antimanual' ) );
        }

        $repo = self::request_json( '/repos/' . $full_name, [], $token );

        if ( is_wp_error( $repo ) ) {
            return $repo;
        }

        $include_readme       = ! empty( $options['include_readme'] );
        $include_docs         = ! empty( $options['include_docs'] );
        $include_root_markdown = ! empty( $options['include_root_markdown'] );

        $sections = [];
        $topics   = isset( $repo['topics'] ) && is_array( $repo['topics'] ) ? implode( ', ', $repo['topics'] ) : '';

        $sections[] = sprintf(
            "%s\n%s\n%s\n",
            sprintf( __( 'Repository: %s', 'antimanual' ), $repo['full_name'] ?? $full_name ),
            sprintf( __( 'Description: %s', 'antimanual' ), $repo['description'] ?? __( 'No description', 'antimanual' ) ),
            $topics ? sprintf( __( 'Topics: %s', 'antimanual' ), $topics ) : __( 'Topics: None', 'antimanual' )
        );

        $default_branch = $repo['default_branch'] ?? 'main';

        if ( $include_readme ) {
            $readme = self::request_raw( '/repos/' . $full_name . '/readme', [ 'ref' => $default_branch ], $token );

            if ( ! is_wp_error( $readme ) && ! empty( $readme ) ) {
                $sections[] = "# README\n" . $readme;
            }
        }

        $max_files     = 25;
        $max_file_size = 200000;
        $max_chars     = 400000;
        $collected     = 0;
        $char_count    = strlen( implode( "\n\n", $sections ) );

        if ( $include_docs || $include_root_markdown ) {
            $tree = self::request_json( '/repos/' . $full_name . '/git/trees/' . $default_branch, [ 'recursive' => 1 ], $token );

            if ( ! is_wp_error( $tree ) && ! empty( $tree['tree'] ) && is_array( $tree['tree'] ) ) {
                foreach ( $tree['tree'] as $item ) {
                    if ( $collected >= $max_files || $char_count >= $max_chars ) {
                        break;
                    }

                    if ( ( $item['type'] ?? '' ) !== 'blob' ) {
                        continue;
                    }

                    $path = $item['path'] ?? '';
                    $size = $item['size'] ?? 0;

                    if ( empty( $path ) || $size > $max_file_size ) {
                        continue;
                    }

                    $is_markdown = preg_match( '/\.(md|mdx|txt)$/i', $path );
                    if ( ! $is_markdown ) {
                        continue;
                    }

                    $is_docs = preg_match( '#^(docs|doc|documentation)/#i', $path );
                    $is_root = strpos( $path, '/' ) === false;

                    if ( ( $is_docs && $include_docs ) || ( $is_root && $include_root_markdown ) ) {
                        $content = self::request_raw( '/repos/' . $full_name . '/contents/' . $path, [ 'ref' => $default_branch ], $token );

                        if ( is_wp_error( $content ) || empty( $content ) ) {
                            continue;
                        }

                        $sections[] = "# " . $path . "\n" . $content;
                        $collected++;
                        $char_count += strlen( $content );
                    }
                }
            }
        }

        $content = trim( implode( "\n\n", $sections ) );

        if ( empty( $content ) ) {
            return new \WP_Error( 'empty_content', __( 'Unable to extract content from this repository.', 'antimanual' ) );
        }

        $repo_url = $repo['html_url'] ?? ( 'https://github.com/' . $full_name );

        $chunks = Embedding::insert([
            'content' => $content,
            'type'    => 'github',
            'url'     => $repo_url,
        ]);

        return $chunks;
    }

    private static function request_json( string $path, array $params = [], string $token = '' ) {
        $url = 'https://api.github.com' . $path;
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $headers = [
            'User-Agent' => 'Antimanual',
            'Accept'     => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        if ( ! empty( $token ) ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get( $url, [
            'headers' => $headers,
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 400 ) {
            $message = $data['message'] ?? __( 'GitHub API request failed.', 'antimanual' );
            return new \WP_Error( 'github_api_error', $message );
        }

        return is_array( $data ) ? $data : [];
    }

    private static function request_raw( string $path, array $params = [], string $token = '' ) {
        $url = 'https://api.github.com' . $path;
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $headers = [
            'User-Agent' => 'Antimanual',
            'Accept'     => 'application/vnd.github.raw',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        if ( ! empty( $token ) ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get( $url, [
            'headers' => $headers,
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code >= 400 ) {
            return new \WP_Error( 'github_api_error', __( 'GitHub API request failed.', 'antimanual' ) );
        }

        return is_string( $body ) ? $body : '';
    }
}
