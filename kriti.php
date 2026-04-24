<?php
/**
 * Plugin Name:       Kriti Bangla Fonts
 * Plugin URI:        https://kriti.app
 * Description:       Integrate Bangla fonts via Kriti CDN or locally hosted files.
 * Version:           1.0.2
 * Author:            Sayed
 * Text Domain:       kriti-bangla-fonts
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'KRITI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KRITI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KRITI_PLUGIN_VERSION', '1.0.2' );

class Kriti_Fonts {

    private $option_name = 'kriti_fonts_settings';
    private $allowed_targets = array( 'global', 'headings', 'paragraphs' );
    private $allowed_delivery_methods = array( 'cdn', 'host' );

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_kriti_save_font', array( $this, 'ajax_save_font' ) );
        add_action( 'wp_ajax_kriti_reset_font', array( $this, 'ajax_reset_font' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_font' ), 999 );

        $plugin_basename = plugin_basename( __FILE__ );
        add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'add_plugin_action_links' ) );
    }

    private function is_valid_target( $target ) {
        return in_array( $target, $this->allowed_targets, true );
    }

    private function is_valid_delivery_method( $method ) {
        return in_array( $method, $this->allowed_delivery_methods, true );
    }

    private function is_valid_kriti_download_url( $url ) {
        $parsed_url = wp_parse_url( $url );

        if ( ! is_array( $parsed_url ) ) {
            return false;
        }

        if ( empty( $parsed_url['scheme'] ) || 'https' !== strtolower( $parsed_url['scheme'] ) ) {
            return false;
        }

        if ( empty( $parsed_url['host'] ) || 'kriti.app' !== strtolower( $parsed_url['host'] ) ) {
            return false;
        }

        $path      = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        return 'woff2' === $extension;
    }

    public function add_plugin_action_links( $links ) {
        $settings_link = '<a href="admin.php?page=kriti-fonts">' . esc_html__( 'Settings', 'kriti-bangla-fonts' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function add_admin_menu() {
        add_menu_page(
            __( 'Kriti Fonts', 'kriti-bangla-fonts' ),
            __( 'Kriti Fonts', 'kriti-bangla-fonts' ),
            'manage_options',
            'kriti-fonts',
            array( $this, 'render_admin_page' ),
            KRITI_PLUGIN_URL . 'icon.svg',
            80 // Position in the menu
        );
    }

    public function register_settings() {
        register_setting( 'kriti_fonts_group', $this->option_name, array(
            'sanitize_callback' => array( $this, 'sanitize_settings_data' )
        ) );
    }

    public function sanitize_settings_data( $input ) {
        $sanitized = array();
        if ( isset( $input['delivery_method'] ) ) {
            $delivery_method = sanitize_key( $input['delivery_method'] );
            $sanitized['delivery_method'] = $this->is_valid_delivery_method( $delivery_method ) ? $delivery_method : 'cdn';
        }
        if ( isset( $input['assignments'] ) && is_array( $input['assignments'] ) ) {
            $sanitized['assignments'] = array();
            foreach ( $input['assignments'] as $target => $data ) {
                $target_key = sanitize_key( $target );
                if ( ! $this->is_valid_target( $target_key ) ) {
                    continue;
                }

                $sanitized['assignments'][ $target_key ] = array(
                    'font_id'   => isset( $data['font_id'] ) ? sanitize_key( $data['font_id'] ) : '',
                    'font_name' => isset( $data['font_name'] ) ? sanitize_text_field( $data['font_name'] ) : '',
                    'font_url'  => isset( $data['font_url'] ) ? esc_url_raw( $data['font_url'] ) : '',
                );
            }
        }
        return $sanitized;
    }

    public function enqueue_admin_assets( $hook_suffix ) {
        if ( 'toplevel_page_kriti-fonts' !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style( 'kriti-admin-css', KRITI_PLUGIN_URL . 'admin.css', array(), KRITI_PLUGIN_VERSION );
        wp_enqueue_script( 'kriti-admin-js', KRITI_PLUGIN_URL . 'admin.js', array( 'jquery' ), KRITI_PLUGIN_VERSION, true );

        $metadata = $this->get_fonts_metadata();

        wp_localize_script( 'kriti-admin-js', 'kritiData', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'kriti_save_font_nonce' ),
            'searchData' => $metadata['searchData'],
            'fontsData'  => $metadata['fontsData'],
            'settings'   => get_option( $this->option_name, array( 'delivery_method' => 'cdn', 'assignments' => array() ) ),
            'i18n'       => array(
                'saving'    => __( 'Saving...', 'kriti-bangla-fonts' ),
                'saved'     => __( 'Saved successfully!', 'kriti-bangla-fonts' ),
                'error'     => __( 'Error saving font.', 'kriti-bangla-fonts' ),
                'resetting' => __( 'Resetting...', 'kriti-bangla-fonts' ),
                'resetMsg'  => __( 'Font removed and reset to default.', 'kriti-bangla-fonts' ),
            )
        ) );
    }

    private function get_fonts_metadata() {
        $transient_key = 'kriti_fonts_metadata';
        $data = get_transient( $transient_key );

        if ( false === $data || empty( $data['searchData'] ) || empty( $data['fontsData'] ) ) {
            $request_args    = array(
                'timeout'     => 15,
                'redirection' => 3,
            );
            $search_response = wp_safe_remote_get( 'https://kriti.app/metadata/search-index.json', $request_args );
            $fonts_response  = wp_safe_remote_get( 'https://kriti.app/cdn/fonts.json', $request_args );

            if ( is_wp_error( $search_response ) || is_wp_error( $fonts_response ) ) {
                return array( 'searchData' => array(), 'fontsData' => array() );
            }

            if ( 200 !== (int) wp_remote_retrieve_response_code( $search_response ) || 200 !== (int) wp_remote_retrieve_response_code( $fonts_response ) ) {
                return array( 'searchData' => array(), 'fontsData' => array() );
            }

            $search_body = wp_remote_retrieve_body( $search_response );
            $fonts_body  = wp_remote_retrieve_body( $fonts_response );

            $search_data = json_decode( $search_body, true );
            $fonts_data  = json_decode( $fonts_body, true );

            if ( is_array( $search_data ) && is_array( $fonts_data ) && ! empty( $search_data ) && ! empty( $fonts_data ) ) {
                $data = array(
                    'searchData' => $search_data,
                    'fontsData'  => $fonts_data,
                );
                // Cache for 72 hours
                set_transient( $transient_key, $data, 72 * HOUR_IN_SECONDS );
            } else {
                $data = array( 'searchData' => array(), 'fontsData' => array() );
            }
        }

        return $data ? $data : array( 'searchData' => array(), 'fontsData' => array() );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = get_option( $this->option_name, array( 'delivery_method' => 'cdn', 'assignments' => array() ) );
        $assignments = isset( $settings['assignments'] ) ? $settings['assignments'] : array();
        
        if ( ! empty( $settings['selected_font'] ) ) {
            $assignments['global'] = array(
                'font_id'   => sanitize_key( $settings['selected_font'] ),
                'font_name' => sanitize_text_field( $settings['selected_font'] ),
                'font_url'  => isset( $settings['font_url'] ) ? esc_url_raw( $settings['font_url'] ) : '',
            );
            $settings['assignments'] = $assignments;
            unset( $settings['selected_font'], $settings['font_url'] );
            update_option( $this->option_name, $settings );
        }

        $delivery_method = isset( $settings['delivery_method'] ) ? $settings['delivery_method'] : 'cdn';
        if ( ! $this->is_valid_delivery_method( $delivery_method ) ) {
            $delivery_method = 'cdn';
        }
        
        $targets = array(
            'global'     => __( 'Global Typography (Body & All Text)', 'kriti-bangla-fonts' ),
            'headings'   => __( 'Headings Only (H1 - H6)', 'kriti-bangla-fonts' ),
            'paragraphs' => __( 'Paragraphs Only (P tags)', 'kriti-bangla-fonts' ),
        );
        ?>
        <div class="wrap kriti-wrap">
            <h1><?php esc_html_e( 'Kriti Fonts Settings', 'kriti-bangla-fonts' ); ?></h1>
            
            <div class="kriti-current-status" style="background:#fff; border:1px solid #c3c4c7; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                <h3 style="margin-top:0; border-bottom:1px solid #f0f0f1; padding-bottom:15px; margin-bottom:15px;"><?php esc_html_e( 'Actively Used Fonts', 'kriti-bangla-fonts' ); ?></h3>
                
                <?php
                $global_assignment = isset( $assignments['global'] ) && is_array( $assignments['global'] ) ? $assignments['global'] : array();
                foreach ( $targets as $key => $label ) :
                    $has_explicit_assignment = isset( $assignments[ $key ] ) && is_array( $assignments[ $key ] );
                    $is_inherited_from_global = ! $has_explicit_assignment && 'global' !== $key && ! empty( $global_assignment );
                    $display_assignment = $has_explicit_assignment ? $assignments[ $key ] : ( $is_inherited_from_global ? $global_assignment : array() );
                    $is_assigned = ! empty( $display_assignment );
                    $current_font_name = $is_assigned ? ( isset( $display_assignment['font_name'] ) ? $display_assignment['font_name'] : $display_assignment['font_id'] ) : '';
                    $current_method = $is_assigned ? ( isset( $settings['delivery_method'] ) ? $settings['delivery_method'] : 'cdn' ) : '';
                    $method_display = $current_method === 'host' ? __( ' (Self Hosted)', 'kriti-bangla-fonts' ) : ( $current_method === 'cdn' ? __( ' (via CDN)', 'kriti-bangla-fonts' ) : '' );
                    if ( $is_inherited_from_global ) {
                        $method_display .= __( ' (Inherited from Global)', 'kriti-bangla-fonts' );
                    }
                ?>
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px; background: #f6f7f7; border-radius: 8px; margin-bottom: 10px;">
                    <div>
                        <strong><?php echo esc_html( $label ); ?>:</strong>
                        <span class="kriti-active-font-name" data-target="<?php echo esc_attr( $key ); ?>" style="font-size:15px; margin-left:10px; color:#2271b1; font-weight:600;">
                            <?php echo $current_font_name ? esc_html( $current_font_name ) . esc_html( $method_display ) : esc_html__( 'System Default', 'kriti-bangla-fonts' ); ?>
                        </span>
                    </div>
                    <button type="button" class="button button-secondary kriti-reset-font" data-target="<?php echo esc_attr( $key ); ?>" <?php echo ( empty( $current_font_name ) || $is_inherited_from_global ) ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e( 'Remove', 'kriti-bangla-fonts' ); ?>
                    </button>
                </div>
                <?php endforeach; ?>
                <div id="kriti-reset-status" style="display:none; font-size:14px; margin-top: 15px; text-align: center; width: 100%; border-radius: 20px; padding: 8px 16px;" class="status-success"></div>
            </div>

            <div class="kriti-controls">
                <div class="kriti-search-pagination">
                    <input type="text" id="kriti-search" placeholder="<?php esc_attr_e( 'Search fonts...', 'kriti-bangla-fonts' ); ?>">
                </div>
            </div>

            <div id="kriti-fonts-grid" class="kriti-grid">
                <!-- Javascript will populate this -->
            </div>
            
            <div id="kriti-pagination-container" style="margin-top: 20px; display: flex; justify-content: center;">
                <div id="kriti-pagination" class="tablenav-pages"></div>
            </div>

            <div id="kriti-modal" class="kriti-modal" style="display:none;">
                <div class="kriti-modal-content">
                    <span class="kriti-close">&times;</span>
                    <h2 id="kriti-modal-title"></h2>
                    
                    <h2 class="nav-tab-wrapper kriti-modal-tabs" style="margin-bottom: 15px;">
                        <a href="#" class="nav-tab nav-tab-active" data-tab="preview"><?php esc_html_e( 'Preview & Save', 'kriti-bangla-fonts' ); ?></a>
                        <a href="#" class="nav-tab" data-tab="metadata"><?php esc_html_e( 'Metadata', 'kriti-bangla-fonts' ); ?></a>
                    </h2>
                    
                    <div id="kriti-tab-preview" class="kriti-tab-content">
                        <textarea id="kriti-modal-preview-text" rows="3" placeholder="<?php esc_attr_e( 'এখানে টাইপ করুন...', 'kriti-bangla-fonts' ); ?>">এখানে টাইপ করুন...</textarea>
                        <div id="kriti-modal-preview-box" class="preview-box">এখানে টাইপ করুন...</div>
                        
                        <div class="kriti-modal-settings-box">
                            <div style="margin-bottom: 15px; width: 100%;">
                                <label style="font-weight: 600; display: block; margin-bottom: 8px;"><?php esc_html_e( 'Font Delivery Method:', 'kriti-bangla-fonts' ); ?></label>
                                <label style="margin-right: 15px;">
                                    <input type="radio" name="kriti_delivery_method" value="cdn" <?php checked( $delivery_method, 'cdn' ); ?>> <?php esc_html_e( 'Font CDN (Fast)', 'kriti-bangla-fonts' ); ?>
                                </label>
                                <label>
                                    <input type="radio" name="kriti_delivery_method" value="host" <?php checked( $delivery_method, 'host' ); ?>> <?php esc_html_e( 'Host Locally', 'kriti-bangla-fonts' ); ?>
                                </label>
                            </div>

                            <div style="margin-bottom: 0px; width: 100%;">
                                <label style="font-weight: 600; display: block; margin-bottom: 8px;"><?php esc_html_e( 'Assignment Type:', 'kriti-bangla-fonts' ); ?></label>
                                
                                <label style="margin-right: 15px;">
                                    <input type="radio" name="kriti_assignment_mode" value="global" checked> <?php esc_html_e( 'Global', 'kriti-bangla-fonts' ); ?>
                                </label>
                                <label>
                                    <input type="radio" name="kriti_assignment_mode" value="custom"> <?php esc_html_e( 'Custom', 'kriti-bangla-fonts' ); ?>
                                </label>
                                
                                <div id="kriti-custom-targets-wrap" style="display:none; margin-top: 10px;">
                                    <label for="kriti-font-target" style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Select Target:', 'kriti-bangla-fonts' ); ?></label>
                                    <select id="kriti-font-target" style="width: 100%; padding: 8px; border-radius: 8px; font-size: 15px; background: #fff; border: 1px solid #8c8f94;">
                                        <?php foreach ( $targets as $key => $label ) : 
                                            if ( 'global' === $key ) {
                                                continue;
                                            }
                                        ?>
                                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; margin-top: 20px;">
                            <button class="button button-primary" id="kriti-select-font" style="margin-top: 0; flex-shrink: 0;"><?php esc_html_e( 'Select & Save Font', 'kriti-bangla-fonts' ); ?></button>
                            <div id="kriti-save-status" style="display: none; align-items: center;"></div>
                        </div>
                    </div>

                    <div id="kriti-tab-metadata" class="kriti-tab-content" style="display:none;">
                        <div id="kriti-metadata-content" style="background:#f6f7f7; padding: 15px; border:1px solid #c3c4c7;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_reset_font() {
        check_ajax_referer( 'kriti_save_font_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'kriti-bangla-fonts' ), 403 );
        }

        $target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
        if ( ! $this->is_valid_target( $target ) ) {
            wp_send_json_error( __( 'Invalid target.', 'kriti-bangla-fonts' ), 400 );
        }

        $settings = get_option( $this->option_name, array() );
        if ( ! isset( $settings['assignments'] ) || ! is_array( $settings['assignments'] ) ) {
            $settings['assignments'] = array();
        }
        
        if ( isset( $settings['assignments'][ $target ] ) ) {
            unset( $settings['assignments'][ $target ] );
        }
        update_option( $this->option_name, $settings );

        wp_send_json_success( array( 'message' => __( 'Font reset successfully.', 'kriti-bangla-fonts' ) ) );
    }

    public function ajax_save_font() {
        check_ajax_referer( 'kriti_save_font_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'kriti-bangla-fonts' ), 403 );
        }

        $delivery_method = isset( $_POST['delivery_method'] ) ? sanitize_key( wp_unslash( $_POST['delivery_method'] ) ) : 'cdn';
        $target          = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'global';
        $font_id         = isset( $_POST['font_id'] ) ? sanitize_key( wp_unslash( $_POST['font_id'] ) ) : '';
        $font_name       = isset( $_POST['font_name'] ) ? sanitize_text_field( wp_unslash( $_POST['font_name'] ) ) : $font_id;
        $download_url    = isset( $_POST['download_url'] ) ? esc_url_raw( wp_unslash( $_POST['download_url'] ) ) : '';

        if ( ! $this->is_valid_delivery_method( $delivery_method ) ) {
            $delivery_method = 'cdn';
        }

        if ( ! $this->is_valid_target( $target ) ) {
            $target = 'global';
        }

        if ( empty( $font_id ) ) {
            wp_send_json_error( __( 'Invalid font selection.', 'kriti-bangla-fonts' ), 400 );
        }

        if ( 'host' === $delivery_method && empty( $download_url ) ) {
            wp_send_json_error( __( 'Missing download URL.', 'kriti-bangla-fonts' ), 400 );
        }

        if ( ! empty( $download_url ) && ! $this->is_valid_kriti_download_url( $download_url ) ) {
            wp_send_json_error( __( 'Security error: Invalid download source. Fonts can only be downloaded from kriti.app.', 'kriti-bangla-fonts' ), 400 );
        }

        if ( empty( $target ) ) {
            $target = 'global';
        }

        if ( 'host' === $delivery_method ) {
            $local_url = $this->download_font( $download_url, $font_id );
            if ( is_wp_error( $local_url ) ) {
                wp_send_json_error( $local_url->get_error_message() );
            }
            $final_url = $local_url;
        } else {
            $final_url = esc_url_raw( $download_url ); // Use CDN URL
        }

        $settings = get_option( $this->option_name, array( 'delivery_method' => 'cdn', 'assignments' => array() ) );
        if ( ! isset( $settings['assignments'] ) || ! is_array( $settings['assignments'] ) ) {
            $settings['assignments'] = array();
        }

        // If setting 'global', clear out the specific tags to override them
        if ( 'global' === $target ) {
            $settings['assignments'] = array();
        }

        $settings['delivery_method'] = $delivery_method;
        $settings['assignments'][ $target ] = array(
            'font_id'   => $font_id,
            'font_name' => $font_name,
            'font_url'  => $final_url,
        );

        update_option( $this->option_name, $settings );

        wp_send_json_success( array( 'message' => __( 'Font settings saved.', 'kriti-bangla-fonts' ), 'settings' => $settings ) );
    }

    private function download_font( $url, $font_id ) {
        if ( empty( $url ) || ! $this->is_valid_kriti_download_url( $url ) ) {
            return new WP_Error( 'invalid_url', __( 'Invalid download URL.', 'kriti-bangla-fonts' ) );
        }

        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        
        $tmp_file = download_url( $url, 30 );
        if ( is_wp_error( $tmp_file ) ) {
            return $tmp_file;
        }

        $font_file_name = sanitize_file_name( $font_id ) . '.woff2';
        if ( '.woff2' === $font_file_name ) {
            $font_file_name = 'kriti-font.woff2';
        }

        $file_array = array(
            'name'     => $font_file_name,
            'tmp_name' => $tmp_file,
            'type'     => 'font/woff2',
        );

        $add_woff2_mime = function( $mimes ) {
            $mimes['woff2'] = 'font/woff2';
            return $mimes;
        };
        add_filter( 'upload_mimes', $add_woff2_mime );

        $check_filetype = function( $data, $file, $filename, $mimes ) {
            $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
            if ( 'woff2' === $ext ) {
                $data['ext']  = 'woff2';
                $data['type'] = 'font/woff2';
                $data['proper_filename'] = $filename;
            }
            return $data;
        };
        add_filter( 'wp_check_filetype_and_ext', $check_filetype, 10, 4 );

        $upload = wp_handle_sideload( $file_array, array(
            'test_form' => false,
            'test_type' => false,
        ) );

        remove_filter( 'upload_mimes', $add_woff2_mime );
        remove_filter( 'wp_check_filetype_and_ext', $check_filetype );

        if ( isset( $upload['error'] ) ) {
            if ( is_string( $tmp_file ) && file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }
            return new WP_Error( 'upload_error', $upload['error'] );
        }

        if ( empty( $upload['url'] ) ) {
            return new WP_Error( 'upload_error', __( 'Unable to save the downloaded font file.', 'kriti-bangla-fonts' ) );
        }

        return esc_url_raw( $upload['url'] );
    }

    public function enqueue_frontend_font() {
        $settings = get_option( $this->option_name, array() );
        $assignments = isset( $settings['assignments'] ) && is_array( $settings['assignments'] ) ? $settings['assignments'] : array();

        if ( empty( $assignments ) ) {
            return;
        }

        $unique_fonts = array();
        foreach ( $assignments as $target => $data ) {
            if ( ! $this->is_valid_target( sanitize_key( $target ) ) ) {
                continue;
            }

            if ( ! empty( $data['font_id'] ) && ! empty( $data['font_url'] ) ) {
                $font_id  = sanitize_key( $data['font_id'] );
                $font_url = esc_url_raw( $data['font_url'] );

                if ( '' !== $font_id && '' !== $font_url ) {
                    $unique_fonts[ $font_id ] = $font_url;
                }
            }
        }

        if ( empty( $unique_fonts ) ) {
            return;
        }

        $css = '';
        // Output font-faces
        foreach ( $unique_fonts as $font_id => $font_url ) {
            $css .= sprintf(
                "@font-face { font-family: 'Kriti-%s'; src: url('%s') format('woff2'); font-display: swap; }\n",
                esc_attr( $font_id ),
                esc_url( $font_url )
            );
        }

        // Global assignment should always override any legacy per-target assignments.
        $global_font_id = '';
        if ( isset( $assignments['global']['font_id'] ) ) {
            $global_font_id = sanitize_key( $assignments['global']['font_id'] );
        }

        if ( '' !== $global_font_id ) {
            $css .= sprintf(
                "body, p, h1, h2, h3, h4, h5, h6, a, span, div, li, ul, ol { font-family: 'Kriti-%s', sans-serif !important; }\n",
                esc_attr( $global_font_id )
            );
        } else {
            // Apply target CSS Rules
            foreach ( $assignments as $target => $data ) {
                $target = sanitize_key( $target );
                if ( ! $this->is_valid_target( $target ) || empty( $data['font_id'] ) ) {
                    continue;
                }

                $font_id = sanitize_key( $data['font_id'] );
                if ( '' === $font_id ) {
                    continue;
                }

                if ( 'headings' === $target ) {
                    $css .= sprintf(
                        "h1, h2, h3, h4, h5, h6 { font-family: 'Kriti-%s', sans-serif !important; }\n",
                        esc_attr( $font_id )
                    );
                } elseif ( 'paragraphs' === $target ) {
                    $css .= sprintf(
                        "p { font-family: 'Kriti-%s', sans-serif !important; }\n",
                        esc_attr( $font_id )
                    );
                }
            }
        }

        wp_register_style( 'kriti-custom-fonts', false, array(), KRITI_PLUGIN_VERSION );
        wp_enqueue_style( 'kriti-custom-fonts' );
        wp_add_inline_style( 'kriti-custom-fonts', $css );
    }
}

new Kriti_Fonts();
