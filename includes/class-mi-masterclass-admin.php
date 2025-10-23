<?php
/**
 * Klasa zarządzająca panelem administracyjnym
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MI_Masterclass_Admin')) {

class MI_Masterclass_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'M&I Master Class',
            'M&I Master Class',
            'manage_options',
            'mi-masterclass',
            array($this, 'admin_page_registrations'),
            'dashicons-groups',
            30
        );
        
        add_submenu_page(
            'mi-masterclass',
            'Rejestracje',
            'Rejestracje',
            'manage_options',
            'mi-masterclass',
            array($this, 'admin_page_registrations')
        );
        
        add_submenu_page(
            'mi-masterclass',
            'Ustawienia',
            'Ustawienia',
            'manage_options',
            'mi-masterclass-settings',
            array($this, 'admin_page_settings')
        );
    }
    
    public function register_settings() {
        register_setting('mi_masterclass_settings', 'mi_masterclass_notification_emails');
        register_setting('mi_masterclass_settings', 'mi_masterclass_active_limit');
        register_setting('mi_masterclass_settings', 'mi_masterclass_observer_limit');
    }
    
    public function admin_page_registrations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mi_masterclass_registrations';
        
        // Obsługa eksportu CSV
        if (isset($_GET['export']) && $_GET['export'] == 'csv') {
            $this->export_csv();
            return;
        }
        
        // Pobieranie rejestracji
        $registrations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY registration_date DESC");
        
        // Statystyki
        $stats = $this->get_statistics();
        ?>
        <div class="wrap">
            <h1>Rejestracje M&I Master Class</h1>
            
            <!-- Statystyki -->
            <div class="mi-stats-container">
                <h2>Statystyki rejestracji</h2>
                <div class="mi-stats-grid">
                    <?php foreach ($stats as $key => $stat): ?>
                    <div class="mi-stat-box">
                        <h3><?php echo esc_html($stat['date']); ?> - <?php echo esc_html($stat['time']); ?></h3>
                        <p><strong>Udział aktywny:</strong> <?php echo esc_html($stat['active']); ?>/<?php echo esc_html($stat['active_limit']); ?></p>
                        <p><strong>Udział obserwacyjny:</strong> <?php echo esc_html($stat['observer']); ?>/<?php echo esc_html($stat['observer_limit']); ?></p>
                        <div class="mi-progress-bar">
                            <div class="mi-progress-fill" style="width: <?php echo ($stat['total'] / $stat['total_limit']) * 100; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Tabela rejestracji -->
            <h2>Lista uczestników</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=mi-masterclass&export=csv'); ?>" class="button button-primary">
                    Eksportuj do CSV
                </a>
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Imię i nazwisko</th>
                        <th>Nazwa salonu</th>
                        <th>Email</th>
                        <th>Telefon</th>
                        <th>Data wydarzenia</th>
                        <th>Godzina</th>
                        <th>Rodzaj udziału</th>
                        <th>Partner M&I</th>
                        <th>Data rejestracji</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($registrations): ?>
                        <?php foreach ($registrations as $reg): ?>
                        <tr>
                            <td><?php echo esc_html($reg->id); ?></td>
                            <td><?php echo esc_html($reg->name); ?></td>
                            <td><?php echo esc_html($reg->salon_name); ?></td>
                            <td><?php echo esc_html($reg->email); ?></td>
                            <td><?php echo esc_html($reg->phone); ?></td>
                            <td><?php echo esc_html($reg->event_date); ?></td>
                            <td><?php echo esc_html($reg->event_time); ?></td>
                            <td><?php echo esc_html($reg->participation_type); ?></td>
                            <td><?php echo $reg->is_partner == 'tak' ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no-alt"></span>'; ?></td>
                            <td><?php echo esc_html($reg->registration_date); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">Brak rejestracji</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function admin_page_settings() {
        // Pobierz aktualne wartości limitów
        $active_limit = get_option('mi_masterclass_active_limit', 8);
        $observer_limit = get_option('mi_masterclass_observer_limit', 22);
        
        ?>
        <div class="wrap">
            <h1>Ustawienia M&I Master Class</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('mi_masterclass_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mi_masterclass_notification_emails">
                                Emaile do powiadomień
                            </label>
                        </th>
                        <td>
                            <textarea 
                                name="mi_masterclass_notification_emails" 
                                id="mi_masterclass_notification_emails"
                                rows="3" 
                                cols="50"
                                class="large-text"
                            ><?php echo esc_textarea(get_option('mi_masterclass_notification_emails')); ?></textarea>
                            <p class="description">
                                Wprowadź adresy email oddzielone przecinkami. Na te adresy będą przychodzić powiadomienia o nowych rejestracjach.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th colspan="2">
                            <h2>Limity miejsc na każdy termin</h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="mi_masterclass_active_limit">
                                Limit miejsc - Udział aktywny (Master Class)
                            </label>
                        </th>
                        <td>
                            <input 
                                type="number" 
                                name="mi_masterclass_active_limit" 
                                id="mi_masterclass_active_limit"
                                value="<?php echo esc_attr($active_limit); ?>"
                                min="1"
                                max="100"
                                class="small-text"
                            />
                            <p class="description">
                                Maksymalna liczba miejsc dla uczestników aktywnych (praca z Marco na modelkach) dla każdego terminu.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="mi_masterclass_observer_limit">
                                Limit miejsc - Udział obserwacyjny
                            </label>
                        </th>
                        <td>
                            <input 
                                type="number" 
                                name="mi_masterclass_observer_limit" 
                                id="mi_masterclass_observer_limit"
                                value="<?php echo esc_attr($observer_limit); ?>"
                                min="1"
                                max="200"
                                class="small-text"
                            />
                            <p class="description">
                                Maksymalna liczba miejsc dla uczestników obserwacyjnych (udział w pokazie i sesji Q&A) dla każdego terminu.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Zapisz ustawienia'); ?>
            </form>
            
            <h2>Shortcode</h2>
            <p>Użyj poniższego shortcode'u, aby wyświetlić formularz na stronie:</p>
            <code>[mi_masterclass_form]</code>
            
            <h2>Resetowanie rejestracji</h2>
            <p>
                <button class="button button-secondary" onclick="if(confirm('Czy na pewno chcesz usunąć wszystkie rejestracje? Ta operacja jest nieodwracalna!')) { location.href='<?php echo admin_url('admin.php?page=mi-masterclass-settings&reset_registrations=1'); ?>'; }">
                    Usuń wszystkie rejestracje
                </button>
            </p>
            
            <?php
            // Obsługa resetu rejestracji
            if (isset($_GET['reset_registrations'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'mi_masterclass_registrations';
                $wpdb->query("TRUNCATE TABLE $table_name");
                echo '<div class="notice notice-success"><p>Wszystkie rejestracje zostały usunięte.</p></div>';
            }
            ?>
        </div>
        <?php
    }
    
    private function get_statistics() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mi_masterclass_registrations';
        
        // Pobierz dynamiczne limity
        $active_limit = get_option('mi_masterclass_active_limit', 8);
        $observer_limit = get_option('mi_masterclass_observer_limit', 22);
        
        $dates = array(
            '2025-11-09_10:00-14:00' => array('date' => '9 listopada 2025', 'time' => '10:00-14:00'),
            '2025-11-09_15:00-19:00' => array('date' => '9 listopada 2025', 'time' => '15:00-19:00'),
            '2025-11-10_10:00-14:00' => array('date' => '10 listopada 2025', 'time' => '10:00-14:00'),
            '2025-11-10_15:00-19:00' => array('date' => '10 listopada 2025', 'time' => '15:00-19:00')
        );
        
        $stats = array();
        
        foreach ($dates as $key => $info) {
            list($date, $time) = explode('_', $key);
            
            $active_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE event_date = %s AND event_time = %s AND participation_type = 'active'",
                $date, $time
            ));
            
            $observer_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE event_date = %s AND event_time = %s AND participation_type = 'observer'",
                $date, $time
            ));
            
            $stats[$key] = array(
                'date' => $info['date'],
                'time' => $info['time'],
                'active' => $active_count,
                'observer' => $observer_count,
                'total' => $active_count + $observer_count,
                'active_limit' => $active_limit,
                'observer_limit' => $observer_limit,
                'total_limit' => $active_limit + $observer_limit
            );
        }
        
        return $stats;
    }
    
    private function export_csv() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mi_masterclass_registrations';
        
        $registrations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY registration_date DESC", ARRAY_A);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mi-masterclass-registrations-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // BOM dla polskich znaków w Excel
        echo "\xEF\xBB\xBF";
        
        $output = fopen('php://output', 'w');
        
        // Nagłówki
        fputcsv($output, array(
            'ID',
            'Imię i nazwisko',
            'Nazwa salonu',
            'Email',
            'Telefon',
            'Data wydarzenia',
            'Godzina',
            'Rodzaj udziału',
            'Partner M&I',
            'Data rejestracji'
        ), ';');
        
        // Dane
        foreach ($registrations as $reg) {
            fputcsv($output, array(
                $reg['id'],
                $reg['name'],
                $reg['salon_name'],
                $reg['email'],
                $reg['phone'],
                $reg['event_date'],
                $reg['event_time'],
                $reg['participation_type'] == 'active' ? 'Aktywny' : 'Obserwacyjny',
                $reg['is_partner'] == 'tak' ? 'Tak' : 'Nie',
                $reg['registration_date']
            ), ';');
        }
        
        fclose($output);
        exit;
    }
}

} // Koniec if class_exists
