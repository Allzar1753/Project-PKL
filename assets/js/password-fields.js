(function () {
    'use strict';

    const supportsMaskedText = !!(window.CSS && CSS.supports('-webkit-text-security', 'disc'));

    function generateStrongPassword(length = 12) {
        const uppers = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        const lowers = 'abcdefghijklmnopqrstuvwxyz';
        const numbers = '0123456789';
        const symbols = '!@#$%^&*';
        const all = uppers + lowers + numbers + symbols;

        let pass = '';
        pass += uppers[Math.floor(Math.random() * uppers.length)];
        pass += lowers[Math.floor(Math.random() * lowers.length)];
        pass += numbers[Math.floor(Math.random() * numbers.length)];
        pass += symbols[Math.floor(Math.random() * symbols.length)];

        while (pass.length < length) {
            pass += all[Math.floor(Math.random() * all.length)];
        }

        return pass.split('').sort(() => 0.5 - Math.random()).join('');
    }

    function isPlainPasswordInput(input) {
        return input.classList.contains('pw-plain') || input.dataset.pwPlain === '1';
    }

    function shouldPrepareInput(input) {
        if (!input || input.dataset.pwInit === '1') {
            return false;
        }

        return input.classList.contains('password-field')
            || input.classList.contains('pw-masked')
            || (input.type === 'password' && !isPlainPasswordInput(input));
    }

    function prepareInput(input) {
        if (!shouldPrepareInput(input)) {
            return;
        }

        input.dataset.pwInit = '1';

        if (isPlainPasswordInput(input)) {
            input.type = 'text';
            return;
        }

        if (supportsMaskedText) {
            input.type = 'text';
            if (!input.classList.contains('pw-visible')) {
                input.classList.add('pw-masked');
            }
            return;
        }

        input.type = 'password';
    }

    function findInput(button) {
        if (button.dataset.target) {
            return document.getElementById(button.dataset.target);
        }

        const wrapper = button.closest('.password-group, .password-shell, .input-shell');
        return wrapper ? wrapper.querySelector('input') : null;
    }

    function updateIcon(icon, isVisible) {
        if (!icon) {
            return;
        }

        icon.classList.toggle('bi-eye', !isVisible);
        icon.classList.toggle('bi-eye-slash', isVisible);
    }

    function toggleInput(input, icon) {
        if (!input) {
            return;
        }

        let isVisible;

        if (input.type === 'password') {
            input.type = 'text';
            isVisible = true;
        } else if (input.classList.contains('pw-masked')) {
            input.classList.remove('pw-masked');
            isVisible = true;
        } else if (supportsMaskedText && !isPlainPasswordInput(input)) {
            input.classList.add('pw-masked');
            isVisible = false;
        } else {
            input.type = 'password';
            isVisible = false;
        }

        updateIcon(icon, isVisible);
    }

    function initScope(scope) {
        scope.querySelectorAll('.password-field, input[type="password"].form-control, input.pw-masked').forEach(prepareInput);
    }

    function copyText(text, label) {
        const done = function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: (label || 'Teks') + ' disalin',
                    showConfirmButton: false,
                    timer: 1800,
                });
            }
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                window.prompt('Salin manual:', text);
            });
            return;
        }

        window.prompt('Salin manual:', text);
    }

    function credentialFieldHtml(label, value, inputId) {
        return ''
            + '<div class="credential-copy-box">'
            + '<label>' + label + '</label>'
            + '<div class="credential-copy-row">'
            + '<input type="text" readonly id="' + inputId + '" value="' + value.replace(/"/g, '&quot;') + '">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary btn-copy-credential" data-copy-target="' + inputId + '" data-copy-label="' + label + '">'
            + '<i class="bi bi-clipboard"></i>'
            + '</button>'
            + '</div>'
            + '</div>';
    }

    function showCredentialsModal(credentials) {
        if (!Array.isArray(credentials) || credentials.length === 0 || typeof Swal === 'undefined') {
            return;
        }

        let html = '<p style="font-size:0.9rem;color:#6b7280;margin-bottom:14px;">Salin email dan password berikut, lalu beritahukan ke user terkait.</p>';

        if (credentials.length === 1) {
            const item = credentials[0];
            html += credentialFieldHtml('Username', item.username || '-', 'credUsername0');
            html += credentialFieldHtml('Email', item.email || '-', 'credEmail0');
            html += credentialFieldHtml('Password', item.password || '-', 'credPassword0');
            html += '<button type="button" class="btn btn-sm btn-outline-dark w-100 mt-1" id="copyAllCredentials">'
                + '<i class="bi bi-clipboard-check me-1"></i> Salin Semua'
                + '</button>';
        } else {
            html += '<div style="max-height:280px;overflow:auto;">';
            credentials.forEach(function (item, index) {
                html += '<div class="credential-user-card">'
                    + '<div style="font-weight:700;margin-bottom:8px;">' + (item.username || '-') + '</div>'
                    + credentialFieldHtml('Email', item.email || '-', 'credEmail' + index)
                    + credentialFieldHtml('Password', item.password || '-', 'credPassword' + index)
                    + '</div>';
            });
            html += '</div>';
            html += '<button type="button" class="btn btn-sm btn-outline-dark w-100 mt-1" id="copyAllCredentials">'
                + '<i class="bi bi-clipboard-check me-1"></i> Salin Semua'
                + '</button>';
        }

        Swal.fire({
            title: credentials.length === 1 ? 'Akun Berhasil Dibuat' : credentials.length + ' Akun Berhasil Dibuat',
            html: html,
            width: 560,
            confirmButtonText: 'Selesai',
            confirmButtonColor: '#E64312',
            didOpen: function () {
                const popup = Swal.getPopup();
                if (!popup) {
                    return;
                }

                popup.querySelectorAll('.btn-copy-credential').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const target = document.getElementById(button.dataset.copyTarget);
                        if (!target) {
                            return;
                        }
                        copyText(target.value, button.dataset.copyLabel || 'Data');
                    });
                });

                const copyAllButton = popup.querySelector('#copyAllCredentials');
                if (copyAllButton) {
                    copyAllButton.addEventListener('click', function () {
                        const lines = credentials.map(function (item) {
                            return 'Username: ' + (item.username || '-')
                                + '\nEmail: ' + (item.email || '-')
                                + '\nPassword: ' + (item.password || '-');
                        });
                        copyText(lines.join('\n\n'), 'Semua kredensial');
                    });
                }
            },
        });
    }

    function showResetCredentialModal(username, email, password, onConfirm) {
        if (typeof Swal === 'undefined') {
            return;
        }

        const generated = password || generateStrongPassword();
        let html = '<p style="font-size:0.9rem;color:#555;margin-bottom:14px;">'
            + 'Password baru untuk <strong>' + username + '</strong> dibuat otomatis. '
            + 'Salin data berikut untuk diberitahukan ke user. User wajib ganti password saat login.'
            + '</p>';
        html += credentialFieldHtml('Email', email, 'resetCredEmail');
        html += credentialFieldHtml('Password Baru', generated, 'resetCredPassword');

        Swal.fire({
            title: 'Reset Password User',
            html: html,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-check2-circle"></i> Proses Reset',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#E64312',
            cancelButtonColor: '#6c757d',
            didOpen: function () {
                const popup = Swal.getPopup();
                if (!popup) {
                    return;
                }

                popup.querySelectorAll('.btn-copy-credential').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const target = document.getElementById(button.dataset.copyTarget);
                        if (!target) {
                            return;
                        }
                        copyText(target.value, button.dataset.copyLabel || 'Data');
                    });
                });
            },
            preConfirm: function () {
                return generated;
            },
        }).then(function (result) {
            if (!result.isConfirmed || typeof onConfirm !== 'function') {
                return;
            }

            Swal.fire({
                title: 'Konfirmasi Final',
                text: 'Password untuk "' + username + '" akan diganti. Lanjutkan?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Selesai!',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6c757d',
            }).then(function (finalResult) {
                if (finalResult.isConfirmed) {
                    onConfirm(result.value);
                }
            });
        });
    }

    document.addEventListener('click', function (event) {
        const toggleButton = event.target.closest('.password-toggle, .toggle-password');
        if (toggleButton) {
            event.preventDefault();
            const input = findInput(toggleButton);
            const icon = toggleButton.querySelector('i');
            toggleInput(input, icon);
            return;
        }

        const generateButton = event.target.closest('.btn-generate-password');
        if (generateButton) {
            const target = document.getElementById(generateButton.dataset.target || '');
            if (!target) {
                return;
            }

            target.value = generateStrongPassword();
            target.type = 'text';
            target.classList.remove('pw-masked');
            target.dataset.pwPlain = '1';
            target.classList.add('pw-plain');
            return;
        }

        const clearButton = event.target.closest('.btn-clear-generated-password');
        if (clearButton) {
            const target = document.getElementById(clearButton.dataset.target || '');
            if (!target) {
                return;
            }

            target.value = '';
            target.classList.remove('pw-plain');
            delete target.dataset.pwPlain;
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        initScope(document);
    });

    window.PasswordFields = {
        init: initScope,
        prepare: prepareInput,
        generateStrongPassword: generateStrongPassword,
        showCredentialsModal: showCredentialsModal,
        showResetCredentialModal: showResetCredentialModal,
        copyText: copyText,
    };
})();
