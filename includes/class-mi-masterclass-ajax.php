<?php
/**
 * AJAX handlers for M&I Master Class Registration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class MI_Masterclass_Ajax {

    /**
     * Nazwa tabeli w DB (wp_ prefiks dodawany dynamicznie)
     * @var string
     */
    private $table;

    /**
     * Limity miejsc (opcje WordPress)
     * @var int
     */
    private $active_limit;
    private $observer_limit;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'mi_masterclass_registrations';

        // Wczytanie limitów z opcji
        $this->active_limit   = absint( get_option('mi_masterclass_active_limit', 8) );
        $this->observer_limit = absint( get_option('mi_masterclass_observer_limit', 22) );

        // Rejestracja endpointów AJAX
        add_action('wp_ajax_mi_masterclass_submit',       [ $this, 'handle_submit' ]);
        add_action('wp_ajax_nopriv_mi_masterclass_submit',[ $this, 'handle_submit' ]);

        // (opcjonalnie) endpoint do sprawdzania zajętości terminów
        add_action('wp_ajax_mi_masterclass_get_counts',        [ $this, 'handle_get_counts' ]);
        add_action('wp_ajax_nopriv_mi_masterclass_get_counts', [ $this, 'handle_get_counts' ]);
    }

    /**
     * Główny handler formularza rejestracji.
     * Oczekiwane pola POST:
     * name, salon_name, email, phone, event_date, event_time, participation_type (active|observer), is_partner (0|1), nonce
     */
    public function handle_submit() {
        // Bezpośrednie wywołania bez POST
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            $this->json_error( 'Invalid request method.' );
        }

        // Nonce
        if ( empty($_POST['nonce']) ) {
            $this->json_error( 'Brak zabezpieczenia. Odśwież stronę i spróbuj ponownie.' );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mi_masterclass_nonce' ) ) {
            $this->json_error( 'Nieprawidłowy token bezpieczeństwa (nonce). Spróbuj ponownie.' );
        }

        // Pobranie i sanityzacja danych
        $name               = $this->sanitize_text( $_POST['name'] ?? '' );
        $salon_name         = $this->sanitize_text( $_POST['salon_name'] ?? '' );
        $email              = $this->sanitize_email( $_POST['email'] ?? '' );
        $phone              = $this->sanitize_text( $_POST['phone'] ?? '' );
        $event_date         = $this->sanitize_text( $_POST['event_date'] ?? '' );
        $event_time         = $this->sanitize_text( $_POST['event_time'] ?? '' );
        $participation_type = $this->sanitize_text( $_POST['participation_type'] ?? '' ); // 'active' | 'observer' (lub PL odpow.)
        $is_partner_raw     = isset($_POST['is_partner']) ? $_POST['is_partner'] : 0;
        $is_partner         = (int) ( (string)$is_partner_raw === '1' || $is_partner_raw === 1 || $is_partner_raw === 'true' );

        // Walidacja wymaganych pól
        $errors = [];
        if ( $name === '' )               { $errors[] = 'Imię i nazwisko jest wymagane.'; }
        if ( $salon_name === '' )         { $errors[] = 'Nazwa salonu jest wymagana.'; }
        if ( $email === '' )              { $errors[] = 'E-mail jest wymagany.'; }
        if ( $email !== '' && ! is_email( $email ) ) {
            $errors[] = 'Podaj poprawny adres e-mail.';
        }
        if ( $phone === '' )              { $errors[] = 'Telefon jest wymagany.'; }
        if ( $event_date === '' )         { $errors[] = 'Data wydarzenia jest wymagana.'; }
        if ( $event_time === '' )         { $errors[] = 'Godzina wydarzenia jest wymagana.'; }
        if ( $participation_type === '' ) { $errors[] = 'Wybierz typ udziału (aktywny/obserwator).'; }

        // Normalizacja participation_type (dopuszczamy kilka wariantów)
        $pt = strtolower( $participation_type );
        if ( in_array( $pt, ['aktywny','active','aktywni'], true ) ) {
            $pt = 'active';
        } elseif ( in_array( $pt, ['obserwator','observer','obserwatorzy'], true ) ) {
            $pt = 'observer';
        }

        if ( ! in_array( $pt, ['active','observer'], true ) ) {
            $errors[] = 'Nieprawidłowy typ udziału.';
        }

        if ( ! empty( $errors ) ) {
            $this->json_error( implode(' ', $errors) );
        }

        // Sprawdzenie duplikatu (ten sam email na ten sam termin)
        if ( $this->is_duplicate_registration( $email, $event_date, $event_time ) ) {
            $this->json_error( 'Wygląda na to, że już zapisałeś/aś się na ten termin tym adresem e-mail.' );
        }

        // Sprawdzenie limitów miejsc dla danego terminu
        $counts = $this->get_counts_for_slot( $event_date, $event_time );
        $limit  = ( $pt === 'active' ) ? $this->active_limit : $this->observer_limit;
        $taken  = ( $pt === 'active' ) ? ( $counts['active'] ?? 0 ) : ( $counts['observer'] ?? 0 );

        if ( $taken >= $limit ) {
            $this->json_error( sprintf(
                'Brak wolnych miejsc dla wybranego typu udziału (%s) w tym terminie.',
                ( $pt === 'active' ? 'aktywny' : 'obserwator' )
            ) );
        }

        // Zapis do DB
        $inserted = $this->insert_registration( [
            'name'               => $name,
            'salon_name'         => $salon_name,
            'email'              => $email,
            'phone'              => $phone,
            'event_date'         => $event_date,
            'event_time'         => $event_time,
            'participation_type' => $pt,
            'is_partner'         => $is_partner ? 1 : 0,
        ] );

        if ( ! $inserted ) {
            $this->json_error( 'Nie udało się zapisać rejestracji. Spróbuj ponownie za chwilę.' );
        }

        // Maile: powiadomienie do organizatorów + potwierdzenie do uczestnika
        $this->send_admin_notification( compact(
            'name','salon_name','email','phone','event_date','event_time','pt','is_partner'
        ) );
        $this->send_user_confirmation( $email, compact(
            'name','salon_name','event_date','event_time','pt','is_partner'
        ) );

        // Sukces
        wp_send_json_success( [
            'message' => 'Zapis zakończony powodzeniem. Wysłaliśmy potwierdzenie na e-mail.',
        ] );
    }

    /**
     * Zwraca zajętość miejsc dla danego terminu (aktywni/obserwatorzy).
     * GET/POST: event_date, event_time
     */
    public function handle_get_counts() {
        $event_date = $this->sanitize_text( $_REQUEST['event_date'] ?? '' );
        $event_time = $this->sanitize_text( $_REQUEST['event_time'] ?? '' );

        if ( $event_date === '' || $event_time === '' ) {
            $this->json_error( 'Brak wymaganych parametrów: event_date, event_time.' );
        }

        $counts = $this->get_counts_for_slot( $event_date, $event_time );

        wp_send_json_success( [
            'counts' => $counts,
            'limits' => [
                'active'   => $this->active_limit,
                'observer' => $this->observer_limit,
            ],
        ] );
    }

    /**
     * Sprawdzenie czy istnieje już rejestracja (prewencja duplikatów).
     */
    private function is_duplicate_registration( $email, $event_date, $event_time ) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE email = %s AND event_date = %s AND event_time = %s
             LIMIT 1",
            $email, $event_date, $event_time
        );

        $found = $wpdb->get_var( $sql );
        return ! empty( $found );
    }

    /**
     * Pobiera zajętość miejsc dla slotu (data+godzina).
     * Zwraca tablicę: ['active' => int, 'observer' => int]
     */
    private function get_counts_for_slot( $event_date, $event_time ) {
        global $wpdb;

        // Zliczamy niezależnie aktywnych i obserwatorów
        $active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE event_date = %s AND event_time = %s AND participation_type = 'active'",
            $event_date, $event_time
        ) );

        $observer = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE event_date = %s AND event_time = %s AND participation_type = 'observer'",
            $event_date, $event_time
        ) );

        return [
            'active'   => $active,
            'observer' => $observer,
        ];
    }

    /**
     * Wstawia rekord do DB.
     */
    private function insert_registration( array $data ) {
        global $wpdb;

        $row = [
            'name'               => $data['name'],
            'salon_name'         => $data['salon_name'],
            'email'              => $data['email'],
            'phone'              => $data['phone'],
            'event_date'         => $data['event_date'],
            'event_time'         => $data['event_time'],
            'participation_type' => $data['participation_type'],
            'is_partner'         => (int) $data['is_partner'],
            // 'registration_date' – ustawiany domyślnie przez DB (CURRENT_TIMESTAMP)
        ];

        $formats = [
            '%s','%s','%s','%s','%s','%s','%s','%d'
        ];

        $ok = $wpdb->insert( $this->table, $row, $formats );
        return (bool) $ok;
    }

    /**
     * Mail do organizatorów
     */
    private function send_admin_notification( array $data ) {
        $to   = get_option( 'mi_masterclass_notification_emails', get_option( 'admin_email' ) );
        if ( empty( $to ) ) {
            return;
        }

        $pt_label = ( $data['pt'] === 'active' ) ? 'Udział aktywny' : 'Obserwator';
        $partner  = $data['is_partner'] ? 'Tak' : 'Nie';

        $subject = sprintf( '[Master Class] Nowa rejestracja: %s (%s)', $data['name'], $pt_label );

        $body = sprintf(
            "Nowe zgłoszenie na Master Class:\n\n".
            "Imię i nazwisko: %s\n".
            "Salon: %s\n".
            "E-mail: %s\n".
            "Telefon: %s\n".
            "Termin: %s %s\n".
            "Typ udziału: %s\n".
            "Partner: %s\n",
            $data['name'],
            $data['salon_name'],
            $data['email'],
            $data['phone'],
            $data['event_date'],
            $data['event_time'],
            $pt_label,
            $partner
        );

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        // Jeśli admin ustawił kilka maili rozdzielonych przecinkami
        $recipients = array_map( 'trim', explode( ',', $to ) );
        foreach ( $recipients as $recipient ) {
            if ( is_email( $recipient ) ) {
                wp_mail( $recipient, $subject, $body, $headers );
            }
        }
    }

    /**
     * Mail potwierdzenia do uczestnika
     */
    private function send_user_confirmation( $email, array $data ) {
        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        $pt_label = ( $data['pt'] === 'active' ) ? 'Udział aktywny' : 'Obserwator';
        $partner  = $data['is_partner'] ? 'Tak' : 'Nie';

        $subject = 'Potwierdzenie zgłoszenia – M&I Master Class';

        $lines = [
            'Dziękujemy za zgłoszenie na M&I Master Class!',
            '',
            'Podsumowanie:',
            '– Imię i nazwisko: ' . $data['name'],
            '– Salon: ' . $data['salon_name'],
            '– Termin: ' . $data['event_date'] . ' ' . $data['event_time'],
            '– Typ udziału: ' . $pt_label,
            '– Partner: ' . $partner,
            '',
            'Wkrótce skontaktujemy się z dalszymi informacjami organizacyjnymi.',
        ];

        $body    = implode( "\n", $lines );
        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        wp_mail( $email, $subject, $body, $headers );
    }

    /**
     * Helper: sanitize tekstu
     */
    private function sanitize_text( $value ) {
        if ( is_array( $value ) ) {
            $value = '';
        }
        return sanitize_text_field( wp_unslash( $value ) );
    }

    /**
     * Helper: sanitize e-mail
     */
    private function sanitize_email( $value ) {
        if ( is_array( $value ) ) {
            $value = '';
        }
        $value = sanitize_email( wp_unslash( $value ) );
        return $value;
    }

    /**
     * Zwraca błąd jako JSON i kończy wykonanie.
     */
    private function json_error( $message, $data = [] ) {
        wp_send_json_error( array_merge( [ 'message' => (string) $message ], $data ) );
    }
}