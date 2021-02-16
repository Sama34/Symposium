(function() {

    Symposium = {

        convid: null,
        lang: {
            responseMalformed: '',
            deleteConfirmation: ''
        },
        numberOfMessages: 0,

        init: function() {

            var d = $('#conversationContainer');
            d.scrollTop(d.prop("scrollHeight"));

            if (Symposium.numberOfMessages == 0) {
                return false;
            }

            $('.deleteMessages').on('click', function(e) {

                e.preventDefault();

                MyBB.prompt(Symposium.lang.deleteConfirmation, {
                    buttons:[
                        {
                            title: yes_confirm,
                            value: true
                        },
                        {
                            title: no_confirm,
                            value: false
                        }
                    ],
                    submit: (e,v,m,f) => {
                        if (v == true) {
                            Symposium.doDeleteMessages();
                        }
                    }
                });

            });

            if (Symposium.numberOfMessages > 0) {

                $('.toggleDeleteMessages').on('click', function(e) {

                    e.preventDefault();
                    $('.delete').toggleClass('hidden');

                    // Some checkboxes are checked, clear them out
                    var checkboxes = $('[data-message-id].delete');
                    if (checkboxes.is(':checked')) {
                        checkboxes.prop('checked', false);
                        $('[id*="pm-"]').removeClass('highlighted');
                    }

                });

                $('[data-message-id].delete').on('change', function(e) {
                    $(this).closest('[id*="pm-"]').toggleClass('highlighted');
                });

            }

        },

        // Grab all form checkboxes and delete the associated messages
        doDeleteMessages: function() {

            var toDelete = [];

            $.each($('[data-message-id].delete:checked'), function() {

                var pmid = Number($(this).data('message-id'));

                if (pmid) {
                    toDelete.push(pmid);
                }

            });

            var data = {
                action: 'symposium_delete_pms',
                pmids: toDelete,
                convid: Symposium.convid
            };

            return Symposium.ajax.request(data, 'POST', (response) => {

                if (response.success) {

                    $.each(toDelete, function(k, v) {
                        $('#pm-' + v).remove();
                    });

                }

            });

        },

        exists: function(variable) {

            return (typeof variable !== 'undefined' && variable != null && variable)
                ? true
                : false;

        },

        ajax: {

            request: function(data, type, callback) {

                if (type == 'POST') {
                    data['my_post_key'] = my_post_key;
                }

                return $.ajax({
                    type: type,
                    url: 'xmlhttp.php',
                    data: data,
                    complete: (xhr, status) => {

                        try {
                            var response = $.parseJSON(xhr.responseText);
                        }
                        catch (e) {
                            console.log(e);
                            return $.jGrowl(Symposium.lang.responseMalformed, {'theme': 'jgrowl_error'});
                        }

                        if (response.errors) {
                            $.each(response.errors, (index, msg) => {
                                $.jGrowl(msg, {'theme': 'jgrowl_error'});
                            });
                        }
                        else if (response.message) {
                            $.jGrowl(response.message, {'theme': 'jgrowl_success'});
                        }

                        if (typeof callback == 'function') {
                            callback(response);
                        }

                    }
                });

            }

        }

    }

})();
