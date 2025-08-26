document.addEventListener('DOMContentLoaded', function() {
    const switchBtns = document.querySelectorAll('.switch-btn');
    const forms = document.querySelectorAll('.form');

    // Переключение между формами
    switchBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const formType = this.getAttribute('data-form');
            
            // Обновляем активные кнопки
            switchBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Переключаем формы
            forms.forEach(form => form.classList.remove('active'));
            document.getElementById(formType + 'Form').classList.add('active');
        });
    });

    // Показать уведомление
    function showNotification(message, type) {
        // Удаляем предыдущие уведомления
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        // Автоматическое скрытие через 5 секунд
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    }

    // Валидация форм
    const setupFormValidation = () => {
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');

        // Валидация логина
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                if (!validateForm(this)) {
                    e.preventDefault();
                }
            });
        }

        // Валидация регистрации
        if (registerForm) {
            registerForm.addEventListener('submit', function(e) {
                const password = this.querySelector('input[name="password"]').value;
                const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    showNotification('Пароли не совпадают', 'error');
                    return;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    showNotification('Пароль должен содержать минимум 6 символов', 'error');
                    return;
                }
                
                if (!validateForm(this)) {
                    e.preventDefault();
                }
            });
        }
    };

    // Основная функция валидации
    function validateForm(form) {
        const inputs = form.querySelectorAll('input[required]');
        let isValid = true;

        inputs.forEach(input => {
            if (!input.value.trim()) {
                highlightError(input);
                isValid = false;
            }
        });

        return isValid;
    }

    // Подсветка ошибки
    function highlightError(input) {
        input.style.borderColor = '#ff4757';
        input.style.animation = 'shake 0.3s ease';
        
        setTimeout(() => {
            input.style.borderColor = '';
            input.style.animation = '';
        }, 2000);
    }

    // Анимация для ошибок
    const style = document.createElement('style');
    style.textContent = `
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
    `;
    document.head.appendChild(style);

    // Инициализация
    setupFormValidation();

    // Обработка уведомлений из URL параметров
    const urlParams = new URLSearchParams(window.location.search);
    const message = urlParams.get('message');
    const type = urlParams.get('type');
    
    if (message && type) {
        showNotification(decodeURIComponent(message), type);
        
        // Очищаем URL параметры
        const cleanUrl = window.location.origin + window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
});