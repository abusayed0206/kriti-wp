<?php
/**
 * Plugin Name:       Kriti: Bangla Fonts CDN & hosted Bangla Fonts
 * Plugin URI:        https://kriti.app
 * Description:       A plugin to integrate Bangla fonts via CDN or locally hosted files.
 * Version:           1.0.0
 * Author:            Sayed
 * Text Domain:       kriti | কৃতি
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'KRITI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KRITI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

class Kriti_Fonts {

    private $option_name = 'kriti_fonts_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_kriti_save_font', array( $this, 'ajax_save_font' ) );
        add_action( 'wp_ajax_kriti_reset_font', array( $this, 'ajax_reset_font' ) );
        add_action( 'wp_head', array( $this, 'enqueue_frontend_font' ) );

        $plugin_basename = plugin_basename( dirname( __FILE__ ) . '/kriti.php' );
        add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'add_plugin_action_links' ) );
    }

    public function add_plugin_action_links( $links ) {
        $settings_link = '<a href="admin.php?page=kriti-fonts">' . esc_html__( 'Settings', 'kriti' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function add_admin_menu() {
        $bd_flag_icon = KRITI_PLUGIN_URL . 'assets/icon.svg';
        
        add_menu_page(
            __( 'Kriti Fonts', 'kriti' ),
            __( 'Kriti Fonts', 'kriti' ),
            'manage_options',
            'kriti-fonts',
            array( $this, 'render_admin_page' ),
            $bd_flag_icon,
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
            $sanitized['delivery_method'] = sanitize_text_field( $input['delivery_method'] );
        }
        if ( isset( $input['assignments'] ) && is_array( $input['assignments'] ) ) {
            $sanitized['assignments'] = array();
            foreach ( $input['assignments'] as $target => $data ) {
                $sanitized['assignments'][ sanitize_text_field( $target ) ] = array(
                    'font_id'   => isset( $data['font_id'] ) ? sanitize_text_field( $data['font_id'] ) : '',
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

        wp_enqueue_style( 'kriti-admin-css', KRITI_PLUGIN_URL . 'assets/admin.css', array(), '1.0.0' );
        wp_enqueue_script( 'kriti-admin-js', KRITI_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), '1.0.0', true );

        $metadata = $this->get_fonts_metadata();

        wp_localize_script( 'kriti-admin-js', 'kritiData', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'kriti_save_font_nonce' ),
            'searchData' => $metadata['searchData'],
            'fontsData'  => $metadata['fontsData'],
            'settings'   => get_option( $this->option_name, array( 'delivery_method' => 'cdn', 'assignments' => array() ) ),
            'i18n'       => array(
                'saving'    => __( 'Saving...', 'kriti' ),
                'saved'     => __( 'Saved successfully!', 'kriti' ),
                'error'     => __( 'Error saving font.', 'kriti' ),
                'resetting' => __( 'Resetting...', 'kriti' ),
                'resetMsg'  => __( 'Font removed and reset to default.', 'kriti' ),
            )
        ) );
    }

    private function get_fonts_metadata() {
        $transient_key = 'kriti_fonts_metadata';
        $data = get_transient( $transient_key );

        if ( false === $data || empty( $data['searchData'] ) || empty( $data['fontsData'] ) ) {
            $search_response = wp_remote_get( 'https://kriti.app/metadata/search-index.json' );
            $fonts_response  = wp_remote_get( 'https://kriti.app/cdn/fonts.json' );

            if ( is_wp_error( $search_response ) || is_wp_error( $fonts_response ) ) {
                return array( 'searchData' => array(), 'fontsData' => array() );
            }

            $search_body = wp_remote_retrieve_body( $search_response );
            $fonts_body  = wp_remote_retrieve_body( $fonts_response );

            $search_data = json_decode( $search_body, true );
            $fonts_data  = json_decode( $fonts_body, true );

            if ( ! empty( $search_data ) && ! empty( $fonts_data ) ) {
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
        
        if ( !empty( $settings['selected_font'] ) ) {
            $assignments['global'] = array(
                'font_id'   => $settings['selected_font'],
                'font_name' => $settings['selected_font'],
                'font_url'  => $settings['font_url']
            );
            $settings['assignments'] = $assignments;
            unset($settings['selected_font'], $settings['font_url']);
            update_option( $this->option_name, $settings );
        }

        $delivery_method = isset( $settings['delivery_method'] ) ? $settings['delivery_method'] : 'cdn';
        
        $targets = array(
            'global'     => __( 'Global Typography (Body & All Text)', 'kriti' ),
            'headings'   => __( 'Headings Only (H1 - H6)', 'kriti' ),
            'paragraphs' => __( 'Paragraphs Only (P tags)', 'kriti' ),
        );
        ?>
        <div class="wrap kriti-wrap">
            <h1><?php esc_html_e( 'Kriti Fonts Settings', 'kriti' ); ?></h1>
            
            <div class="kriti-current-status" style="background:#fff; border:1px solid #c3c4c7; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                <h3 style="margin-top:0; border-bottom:1px solid #f0f0f1; padding-bottom:15px; margin-bottom:15px;"><?php esc_html_e( 'Actively Used Fonts', 'kriti' ); ?></h3>
                
                <?php foreach ( $targets as $key => $label ) : 
                    $is_assigned = isset($assignments[$key]);
                    $current_font_name = $is_assigned ? ( isset($assignments[$key]['font_name']) ? $assignments[$key]['font_name'] : $assignments[$key]['font_id'] ) : '';
                    $current_method = $is_assigned ? ( isset($settings['delivery_method']) ? $settings['delivery_method'] : 'cdn' ) : '';
                    $method_display = $current_method === 'host' ? __( ' (Self Hosted)', 'kriti' ) : ( $current_method === 'cdn' ? __( ' (via CDN)', 'kriti' ) : '' );
                ?>
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px; background: #f6f7f7; border-radius: 8px; margin-bottom: 10px;">
                    <div>
                        <strong><?php echo esc_html( $label ); ?>:</strong>
                        <span class="kriti-active-font-name" data-target="<?php echo esc_attr( $key ); ?>" style="font-size:15px; margin-left:10px; color:#2271b1; font-weight:600;">
                            <?php echo $current_font_name ? esc_html( $current_font_name ) . esc_html( $method_display ) : esc_html__( 'System Default', 'kriti' ); ?>
                        </span>
                    </div>
                    <button type="button" class="button button-secondary kriti-reset-font" data-target="<?php echo esc_attr( $key ); ?>" <?php echo empty( $current_font_name ) ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e( 'Remove', 'kriti' ); ?>
                    </button>
                </div>
                <?php endforeach; ?>
                <div id="kriti-reset-status" style="display:none; font-size:14px; margin-top: 15px; text-align: center; width: 100%; border-radius: 20px; padding: 8px 16px;" class="status-success"></div>
            </div>

            <div class="kriti-controls">
                <div class="kriti-search-pagination">
                    <input type="text" id="kriti-search" placeholder="<?php esc_attr_e( 'Search fonts...', 'kriti' ); ?>">
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
                        <a href="#" class="nav-tab nav-tab-active" data-tab="preview"><?php esc_html_e( 'Preview & Save', 'kriti' ); ?></a>
                        <a href="#" class="nav-tab" data-tab="metadata"><?php esc_html_e( 'Metadata', 'kriti' ); ?></a>
                    </h2>
                    
                    <div id="kriti-tab-preview" class="kriti-tab-content">
                        <textarea id="kriti-modal-preview-text" rows="3" placeholder="<?php esc_attr_e( 'এখানে টাইপ করুন...', 'kriti' ); ?>">এখানে টাইপ করুন...</textarea>
                        <div id="kriti-modal-preview-box" class="preview-box">এখানে টাইপ করুন...</div>
                        
                        <div class="kriti-modal-settings-box">
                            <div style="margin-bottom: 15px; width: 100%;">
                                <label style="font-weight: 600; display: block; margin-bottom: 8px;"><?php esc_html_e( 'Font Delivery Method:', 'kriti' ); ?></label>
                                <label style="margin-right: 15px;">
                                    <input type="radio" name="kriti_delivery_method" value="cdn" <?php checked( $delivery_method, 'cdn' ); ?>> <?php esc_html_e( 'Font CDN (Fast)', 'kriti' ); ?>
                                </label>
                                <label>
                                    <input type="radio" name="kriti_delivery_method" value="host" <?php checked( $delivery_method, 'host' ); ?>> <?php esc_html_e( 'Host Locally', 'kriti' ); ?>
                                </label>
                            </div>

                            <div style="margin-bottom: 0px; width: 100%;">
                                <label style="font-weight: 600; display: block; margin-bottom: 8px;"><?php esc_html_e( 'Assignment Type:', 'kriti' ); ?></label>
                                
                                <label style="margin-right: 15px;">
                                    <input type="radio" name="kriti_assignment_mode" value="global" checked> <?php esc_html_e( 'Global', 'kriti' ); ?>
                                </label>
                                <label>
                                    <input type="radio" name="kriti_assignment_mode" value="custom"> <?php esc_html_e( 'Custom', 'kriti' ); ?>
                                </label>
                                
                                <div id="kriti-custom-targets-wrap" style="display:none; margin-top: 10px;">
                                    <label for="kriti-font-target" style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Select Target:', 'kriti' ); ?></label>
                                    <select id="kriti-font-target" style="width: 100%; padding: 8px; border-radius: 8px; font-size: 15px; background: #fff; border: 1px solid #8c8f94;">
                                        <?php foreach ( $targets as $key => $label ) : 
                                            if ( $key === 'global' ) continue;
                                        ?>
                                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; margin-top: 20px;">
                            <button class="button button-primary" id="kriti-select-font" style="margin-top: 0; flex-shrink: 0;"><?php esc_html_e( 'Select & Save Font', 'kriti' ); ?></button>
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
            wp_send_json_error( 'Unauthorized' );
        }

        $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
        $settings = get_option( $this->option_name, array() );
        if ( !isset( $settings['assignments'] ) ) {
            $settings['assignments'] = array();
        }
        
        if ( $target && isset( $settings['assignments'][$target] ) ) {
            unset( $settings['assignments'][$target] );
        }
        update_option( $this->option_name, $settings );

        wp_send_json_success( array( 'message' => __( 'Font reset successfully.', 'kriti' ) ) );
    }

    public function ajax_save_font() {
        check_ajax_referer( 'kriti_save_font_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $delivery_method = isset( $_POST['delivery_method'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_method'] ) ) : 'cdn';
        $target          = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'global';
        $font_id         = isset( $_POST['font_id'] ) ? sanitize_text_field( wp_unslash( $_POST['font_id'] ) ) : '';
        $font_name       = isset( $_POST['font_name'] ) ? sanitize_text_field( wp_unslash( $_POST['font_name'] ) ) : $font_id;
        $download_url    = isset( $_POST['download_url'] ) ? esc_url_raw( wp_unslash( $_POST['download_url'] ) ) : '';

        // Ensure the font is only being downloaded from the official kriti.app domain.
        if ( 'host' === $delivery_method && ! empty( $download_url ) ) {
            $parsed_url = wp_parse_url( $download_url );
            if ( ! isset( $parsed_url['host'] ) || $parsed_url['host'] !== 'kriti.app' ) {
                wp_send_json_error( __( 'Security error: Invalid download source. Fonts can only be downloaded from kriti.app.', 'kriti' ) );
            }
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
            $final_url = $download_url; // Use CDN URL
        }

        $settings = get_option( $this->option_name, array() );
        if ( !isset( $settings['assignments'] ) ) {
            $settings['assignments'] = array();
        }

        // If setting 'global', clear out the specific tags to override them
        if ( 'global' === $target ) {
            $settings['assignments'] = array();
        }

        $settings['delivery_method'] = $delivery_method;
        $settings['assignments'][$target] = array(
            'font_id'   => $font_id,
            'font_name' => $font_name,
            'font_url'  => $final_url
        );

        update_option( $this->option_name, $settings );

        wp_send_json_success( array( 'message' => __( 'Font settings saved.', 'kriti' ), 'settings' => $settings ) );
    }

    private function download_font( $url, $font_id ) {
        if ( empty( $url ) ) {
            return new WP_Error( 'invalid_url', __( 'Invalid download URL.', 'kriti' ) );
        }

        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        
        $tmp_file = download_url( $url );
        if ( is_wp_error( $tmp_file ) ) {
            return $tmp_file;
        }

        $file_array = array(
            'name'     => sanitize_file_name( $font_id . '.woff2' ),
            'tmp_name' => $tmp_file,
        );

        // Explicitly allow .woff2 files to be safely uploaded
        $mimes = get_allowed_mime_types();
        $mimes['woff2'] = 'font/woff2';

        $upload = wp_handle_sideload( $file_array, array( 
            'test_form' => false,
            'test_type' => false, // Bypass strict WP mime-type sniffing which blocks woff2 even if explicitly allowed
            'mimes'     => $mimes
        ) );

        if ( isset( $upload['error'] ) ) {
            wp_delete_file( $tmp_file );
            return new WP_Error( 'upload_error', $upload['error'] );
        }

        return $upload['url'];
    }

    public function enqueue_frontend_font() {
        $settings = get_option( $this->option_name );
        $assignments = isset( $settings['assignments'] ) ? $settings['assignments'] : array();

        if ( empty( $assignments ) ) {
            return;
        }

        $unique_fonts = array();
        foreach ( $assignments as $target => $data ) {
            if ( ! empty( $data['font_id'] ) && ! empty( $data['font_url'] ) ) {
                $unique_fonts[ $data['font_id'] ] = $data['font_url'];
            }
        }

        if ( empty( $unique_fonts ) ) {
            return;
        }

        echo '<style id="kriti-custom-fonts">';
        // Output font-faces
        foreach ( $unique_fonts as $font_id => $font_url ) {
            printf(
                "@font-face { font-family: 'Kriti-%s'; src: url('%s') format('woff2'); font-display: swap; }\n",
                esc_attr( $font_id ),
                esc_url( $font_url )
            );
        }

        // Apply target CSS Rules
        foreach ( $assignments as $target => $data ) {
            if ( empty( $data['font_id'] ) ) continue;
            
            if ( 'global' === $target ) {
                printf(
                    "body, p, h1, h2, h3, h4, h5, h6, a, span, div, li, ul, ol { font-family: 'Kriti-%s', sans-serif; }\n",
                    esc_attr( $data['font_id'] )
                );
            } elseif ( 'headings' === $target ) {
                printf(
                    "h1, h2, h3, h4, h5, h6 { font-family: 'Kriti-%s', sans-serif !important; }\n",
                    esc_attr( $data['font_id'] )
                );
            } elseif ( 'paragraphs' === $target ) {
                printf(
                    "p { font-family: 'Kriti-%s', sans-serif !important; }\n",
                    esc_attr( $data['font_id'] )
                );
            }
        }
        echo '</style>';
    }
}

new Kriti_Fonts();
