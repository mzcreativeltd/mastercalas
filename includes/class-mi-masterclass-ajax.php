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
     * Mail potwierdzenia do uczestnika — wersja HTML brandowana M&I
     */
    private function send_user_confirmation( $email, array $data ) {
        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        $pt_label = ( $data['pt'] === 'active' ) ? 'Udział aktywny' : 'Obserwator';
        $partner  = $data['is_partner'] ? 'Tak' : 'Nie';

        $subject = 'Potwierdzenie zgłoszenia – M&I Master Class';

        $html = $this->build_mi_brand_email_html( [
            'logo_url'   => 'https://m-and-i.pl/wp-content/uploads/2025/04/mi-czarne-RGB-300-dpi.png',
            'headline'   => 'Dziękujemy za zgłoszenie na M&I Master Class!',
            'intro'      => 'Twoja rejestracja została przyjęta. Poniżej znajdziesz podsumowanie zgłoszenia:',
            'rows'       => [
                [ 'label' => 'Imię i nazwisko', 'value' => $data['name'] ?? '' ],
                [ 'label' => 'Salon',            'value' => $data['salon_name'] ?? '' ],
                [ 'label' => 'Termin',           'value' => trim( ($data['event_date'] ?? '') . ' ' . ($data['event_time'] ?? '') ) ],
                [ 'label' => 'Typ udziału',      'value' => $pt_label ],
                [ 'label' => 'Partner',          'value' => $partner ],
            ],
            'note'       => '',
            'footer'     => 'M&I – Szkolenia i Master Class • m-and-i.pl',
            'cta_text'   => 'Odwiedź m-and-i.pl',
            'cta_href'   => home_url('/'),
        ] );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // (opcjonalnie) ustaw „Reply-To” na e-mail kontaktowy firmy
        $company_reply_to = get_option( 'admin_email' );
        if ( is_email( $company_reply_to ) ) {
            $headers[] = 'Reply-To: ' . $company_reply_to;
        }

        wp_mail( $email, $subject, $html, $headers );
    }

    /**
     * Helper: buduje markowy HTML e-mail w stylu M&I (lekka karta + tabela szczegółów)
     *
     * @param array $args {
     *   @type string   logo_url
     *   @type string   headline
     *   @type string   intro
     *   @type array[]  rows      Każdy element: [ 'label' => '…', 'value' => '…' ]
     *   @type string   note
     *   @type string   footer
     *   @type string   cta_text
     *   @type string   cta_href
     * }
     * @return string
     */
    private function build_mi_brand_email_html( array $args ) {
        // Tokeny kolorów / brand
        $ink     = '#1b1c1e';
        $muted   = '#5f6b7b';
        $accent  = '#2e7d32'; // zielony akcent M&I
        $bg      = '#f7f9fb';
        $card    = '#ffffff';
        $border  = '#e8ecf3';
        $ring    = 'rgba(46,125,50,.12)';

        $logo    = esc_url( $args['logo_url'] ?? '' );
        $headline= esc_html( $args['headline'] ?? '' );
        $intro   = esc_html( $args['intro'] ?? '' );
        $note    = esc_html( $args['note'] ?? '' );
        $footer  = esc_html( $args['footer'] ?? get_bloginfo('name') );
        $cta_txt = esc_html( $args['cta_text'] ?? '' );
        $cta_href= esc_url( $args['cta_href'] ?? '' );

        // Wiersze tabeli szczegółów
        $rows_html = '';
        if ( ! empty( $args['rows'] ) && is_array( $args['rows'] ) ) {
            foreach ( $args['rows'] as $row ) {
                $label = isset($row['label']) ? esc_html( $row['label'] ) : '';
                $value = isset($row['value']) ? esc_html( $row['value'] ) : '';
                $rows_html .= '
                    <tr>
                        <td style="padding:10px 12px;border-bottom:1px solid '.$border.';font-weight:600;color:'.$ink.';white-space:nowrap;">'.$label.'</td>
                        <td style="padding:10px 12px;border-bottom:1px solid '.$border.';color:'.$ink.';">'.$value.'</td>
                    </tr>';
            }
        }

        // Minimalny, kompatybilny HTML (inline CSS – pod klienty pocztowe)
        $html = '
<!doctype html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="x-apple-disable-message-reformatting">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>'. $headline .'</title>
</head>
<body style="margin:0;padding:0;background:'.$bg.';color:'.$ink.';font-family:Arial,Helvetica,sans-serif;line-height:1.55;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:'.$bg.';padding:24px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;width:100%;">
          <!-- Logo -->
          <tr>
            <td align="center" style="padding:8px 0 16px 0;">
              '. ( $logo ? '<img src="'.$logo.'" alt="M&I" width="180" style="max-width:180px;height:auto;display:block;">' : '' ) .'
            </td>
          </tr>

          <!-- Karta -->
          <tr>
            <td style="background:'.$card.';border:1px solid '.$border.';border-radius:14px;box-shadow:0 6px 24px '.$ring.';padding:24px;">
              
              <!-- Nagłówek -->
              <h1 style="margin:0 0 8px 0;font-size:22px;line-height:1.3;color:'.$ink.';font-weight:700;">
                '.$headline.'
              </h1>
              <p style="margin:0 0 18px 0;color:'.$muted.';font-size:15px;">'.$intro.'</p>

              <!-- Divider -->
              <hr style="border:none;border-top:1px solid '.$border.';margin:12px 0 16px 0;">

              <!-- Tabela szczegółów -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                <tbody>
                  '.$rows_html.'
                </tbody>
              </table>

              <!-- Info -->
              <p style="margin:18px 0 0 0;color:'.$ink.';font-size:15px;">'.$note.'</p>

              <!-- CTA -->
              '. ( $cta_txt && $cta_href ? '
              <div style="margin-top:18px;">
                <a href="'.$cta_href.'" target="_blank" 
                   style="display:inline-block;text-decoration:none;background:'.$accent.';color:#fff;padding:12px 18px;border-radius:10px;font-weight:700;">
                   '.$cta_txt.'
                </a>
              </div>' : '' ) .'

            </td>
          </tr>

          <!-- Stopka -->
          <tr>
            <td align="center" style="padding:14px 8px 0 8px;color:'.$muted.';font-size:12px;">
              '.$footer.'<br>
              Ten e-mail został wygenerowany automatycznie – prosimy, nie odpowiadać bezpośrednio.
            </td>
          </tr>

          <tr>
            <td align="center" style="height:18px;"></td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

        return $html;
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
