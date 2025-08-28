document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.querySelector('.login-form');
    const registerForm = document.querySelector('.register-form');
    const showRegisterBtn = document.getElementById('show-register');
    const showLoginBtn = document.getElementById('show-login');
    
    // Переключение между формами
    showRegisterBtn.addEventListener('click', function(e) {
        e.preventDefault();
        loginForm.closest('.form-container').style.display = 'none';
        registerForm.style.display = 'block';
    });
    
    showLoginBtn.addEventListener('click', function(e) {
        e.preventDefault();
        registerForm.style.display = 'none';
        loginForm.closest('.form-container').style.display = 'block';
    });
    
    // Валидация форм
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            const inputs = this.querySelectorAll('input[required]');
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.style.borderColor = 'red';
                    
                    setTimeout(() => {
                        input.style.borderColor = '';
                    }, 2000);
                }
            });
            
            // Проверка подтверждения пароля
            if (this.querySelector('input[name="confirm_password"]')) {
                const password = this.querySelector('input[name="password"]');
                const confirmPassword = this.querySelector('input[name="confirm_password"]');
                
                if (password.value !== confirmPassword.value) {
                    isValid = false;
                    alert('Пароли не совпадают!');
                    confirmPassword.style.borderColor = 'red';
                    
                    setTimeout(() => {
                        confirmPassword.style.borderColor = '';
                    }, 2000);
                }
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });
    });
    
    // Анимация элементов при фокусе
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-2px)';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });
});