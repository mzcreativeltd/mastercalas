(function ($) {
    $(document).on('submit', '#mi-masterclass-form', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('.mi-submit-btn');
        var $spin = $form.find('.mi-loading-spinner');
        var $ok = $form.find('.mi-success-message');
        var $errBox = $form.find('.mi-error-message-general');

        $ok.hide().text('');
        $errBox.hide().text('');

        var $slot = $form.find('#mi-date-selection input[name="event_slot"]:checked');
        if (!$slot.length) {
            // fallback dla dziwnych builderów/stylowanych radiobuttonów
            $slot = $form.find('input[name="event_slot"]').filter(function () { return this.checked; });
        }
        if (!$slot.length) {
            $errBox.text('Wybierz termin wydarzenia.').show();
            return;
        }

        // czytaj z data-* (pewniejsze niż parsowanie value)
        var event_date = $slot.data('date') || String($slot.val()).split('|')[0] || '';
        var event_time = $slot.data('time') || String($slot.val()).split('|')[1] || '';
        
        var payload = {
            action: 'mi_masterclass_submit',
            nonce: (window.mi_masterclass_ajax && window.mi_masterclass_ajax.nonce) || $('#mi_masterclass_nonce').val(),
            name: $form.find('[name="name"]').val(),
            salon_name: $form.find('[name="salon_name"]').val(),
            email: $form.find('[name="email"]').val(),
            phone: $form.find('[name="phone"]').val(),
            event_date: event_date,
            event_time: event_time,
            participation_type: $form.find('input[name="participation_type"]:checked').val(),
            is_partner: $form.find('input[name="is_partner"]:checked').val() || 0
        };

        // Walidacja checkboxów (jeśli chcesz komunikaty szczegółowe)
        if (!$form.find('#mi_privacy_policy').is(':checked')) {
            $errBox.text('Zaznacz zgodę na przetwarzanie danych.').show();
            return;
        }
        if (!$form.find('#mi_terms').is(':checked')) {
            $errBox.text('Zaakceptuj regulamin wydarzenia.').show();
            return;
        }

        // UI: blokada
        $btn.prop('disabled', true);
        $spin.show();

        $.ajax({
            url: (window.mi_masterclass_ajax && window.mi_masterclass_ajax.ajax_url) || (window.ajaxurl || '/wp-admin/admin-ajax.php'),
            method: 'POST',
            dataType: 'json',
            data: payload
        })
            .done(function (resp) {
                if (resp && resp.success) {
                    $ok.text(resp.data && resp.data.message ? resp.data.message : 'Zapisano.').show();
                    $form[0].reset();
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Nie udało się przetworzyć zgłoszenia.';
                    $errBox.text(msg).show();
                }
            })
            .fail(function () {
                $errBox.text('Wystąpił błąd połączenia. Proszę spróbować ponownie.').show();
            })
            .always(function () {
                $btn.prop('disabled', false);
                $spin.hide();
            });
    });
})(jQuery);