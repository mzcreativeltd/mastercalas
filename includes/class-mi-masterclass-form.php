<?php
/**
 * Klasa zarządzająca formularzem rejestracyjnym
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MI_Masterclass_Form')) {

class MI_Masterclass_Form {
    
    public function __construct() {
        add_shortcode('mi_masterclass_form', array($this, 'render_form'));
    }
    
    public function render_form() {
        // Pobierz limity miejsc
        $active_limit   = get_option('mi_masterclass_active_limit', 8);
        $observer_limit = get_option('mi_masterclass_observer_limit', 22);
        $nonce          = wp_create_nonce('mi_masterclass_nonce');
        
        ob_start();
        ?>
        <div id="mi-masterclass-form-container">
            <form id="mi-masterclass-form" class="mi-registration-form" novalidate>
                <input type="hidden" name="mi_masterclass_nonce" id="mi_masterclass_nonce" value="<?php echo esc_attr($nonce); ?>">

                <div class="mi-form-header">
                    <h2>Rejestracja na wydarzenie</h2>
                    <h3>M&I Master Class z Marco Illiges – „Styl, który MI pasuje"</h3>
                </div>
                
                <div class="mi-form-group">
                    <label for="mi_name">Imię i nazwisko <span class="required">*</span></label>
                    <input type="text" id="mi_name" name="name" required>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <label for="mi_salon_name">Nazwa salonu <span class="required">*</span></label>
                    <input type="text" id="mi_salon_name" name="salon_name" required>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <label for="mi_email">Adres e-mail <span class="required">*</span></label>
                    <input type="email" id="mi_email" name="email" required>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <label for="mi_phone">Numer telefonu kontaktowego <span class="required">*</span></label>
                    <input type="tel" id="mi_phone" name="phone" required>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <label>Wybór terminu M&I Master Class <span class="required">*</span></label>
                    <div class="mi-radio-group" id="mi-date-selection">
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_date_1" name="event_slot" data-date="2025-11-09" data-time="10:00-14:00" required>
                            <label for="mi_date_1">9 listopada 2025, godz. 10:00–14:00</label>
                        </div>
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_date_2" name="event_slot" data-date="2025-11-09" data-time="15:00-19:00" required>
                            <label for="mi_date_2">9 listopada 2025, godz. 15:00–19:00</label>
                        </div>
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_date_3" name="event_slot" data-date="2025-11-10" data-time="10:00-14:00" required>
                            <label for="mi_date_3">10 listopada 2025, godz. 10:00–14:00</label>
                        </div>
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_date_4" name="event_slot" data-date="2025-11-10" data-time="15:00-19:00" required>
                            <label for="mi_date_4">10 listopada 2025, godz. 15:00–19:00</label>
                        </div>
                    </div>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <label>Rodzaj udziału <span class="required">*</span></label>
                    <div class="mi-radio-group" id="mi-participation-type">
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_type_active" name="participation_type" value="active" required>
                            <label for="mi_type_active">
                                <strong>Aktywny udział (Master Class)</strong> – praca z Marco na modelkach
                                <span class="mi-type-info" data-limit="<?php echo esc_attr($active_limit); ?>">(<span id="mi-active-spots"><?php echo esc_html($active_limit); ?></span> wolnych miejsc)</span>
                            </label>
                        </div>
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_type_observer" name="participation_type" value="observer" required>
                            <label for="mi_type_observer">
                                <strong>Udział obserwacyjny</strong> – udział w pokazie i sesji Q&A
                                <span class="mi-type-info" data-limit="<?php echo esc_attr($observer_limit); ?>">(<span id="mi-observer-spots"><?php echo esc_html($observer_limit); ?></span> wolnych miejsc)</span>
                            </label>
                        </div>
                    </div>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <label>Czy jesteś zarejestrowanym partnerem M&I? <span class="required">*</span></label>
                    <div class="mi-radio-group">
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_partner_yes" name="is_partner" value="1" required>
                            <label for="mi_partner_yes">Tak</label>
                        </div>
                        <div class="mi-radio-wrapper">
                            <input type="radio" id="mi_partner_no" name="is_partner" value="0" required>
                            <label for="mi_partner_no">Nie</label>
                        </div>
                    </div>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <div class="mi-checkbox-wrapper">
                        <input type="checkbox" id="mi_privacy_policy" name="privacy_policy" required>
                        <label for="mi_privacy_policy">
                            Wyrażam zgodę na przetwarzanie moich danych osobowych w celu realizacji rejestracji na wydarzenie M&I Master Class. <span class="required">*</span>
                        </label>
                    </div>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-group">
                    <div class="mi-checkbox-wrapper">
                        <input type="checkbox" id="mi_terms" name="terms" required>
                        <label for="mi_terms">
                            Akceptuję <a href="https://m-and-i.pl/regulamin-wydarzenia-mi-master-class-z-marco-illiges/" target="_blank" rel="noopener">regulamin wydarzenia</a> <span class="required">*</span>
                        </label>
                    </div>
                    <span class="mi-error-message"></span>
                </div>
                
                <div class="mi-form-submit">
                    <button type="submit" class="mi-submit-btn">Zarejestruj się</button>
                    <div class="mi-loading-spinner" style="display:none;">
                        <span class="spinner"></span> Przetwarzanie...
                    </div>
                </div>
                
                <div class="mi-form-messages">
                    <div class="mi-success-message" style="display:none;"></div>
                    <div class="mi-error-message-general" style="display:none;"></div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

} // if class exists