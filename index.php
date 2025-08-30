<?php
include 'config.php';
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .header {
            padding: 35px 30px 25px;
            text-align: center;
        }
        
        .title {
            color: #2d3748;
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #718096;
            font-size: 16px;
            font-weight: 400;
        }
        
        .form-container {
            padding: 0 30px 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group input {
            width: 100%;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
            color: #2d3748;
            background: #f7fafc;
        }
        
        .form-group input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            outline: none;
            background: white;
        }
        
        .form-group input::placeholder {
            color: #a0aec0;
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 25px;
        }
        
        .forgot-password a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .forgot-password a:hover {
            color: #5a67d8;
            text-decoration: underline;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s, transform 0.2s;
            letter-spacing: 0.5px;
        }
        
        .btn:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .form-footer {
            text-align: center;
            margin-top: 25px;
            color: #718096;
            font-size: 15px;
        }
        
        .form-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .form-footer a:hover {
            color: #5a67d8;
            text-decoration: underline;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 25px 0;
        }
        
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        
        .divider span {
            padding: 0 15px;
            color: #a0aec0;
            font-size: 14px;
        }
        
        .social-login {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        
        .social-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: background 0.3s, transform 0.2s;
        }
        
        .social-btn:hover {
            background: #edf2f7;
            transform: translateY(-2px);
        }
        
        .social-btn i {
            color: #718096;
            font-size: 20px;
        }
        
        .error-message {
            color: #e53e3e;
            background: #fed7d7;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: none;
        }
        
        .success-message {
            color: #38a169;
            background: #c6f6d5;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: none;
        }
        
        .form-toggle {
            text-align: center;
            margin-top: 20px;
        }
        
        .form-toggle a {
            color: #667eea;
            cursor: pointer;
            text-decoration: underline;
        }
        
        #registerForm {
            display: none;
        }
        
        @media (max-width: 576px) {
            .container {
                max-width: 100%;
            }
            
            .header {
                padding: 25px 20px 15px;
            }
            
            .form-container {
                padding: 0 20px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title" id="formTitle">Добро пожаловать</h1>
            <p class="subtitle" id="formSubtitle">Войдите в систему для продолжения</p>
        </div>
        
        <div class="form-container">
            <div class="error-message" id="errorMessage"></div>
            <div class="success-message" id="successMessage"></div>
            
            <form id="loginForm">
                <div class="form-group">
                    <input type="email" name="email" placeholder="email@example.com" required>
                </div>
                
                <div class="form-group">
                    <input type="password" name="password" placeholder="Введите пароль" required>
                </div>
                
                <div class="forgot-password">
                    <a href="#">Забыли пароль?</a>
                </div>
                
                <button type="submit" class="btn">Войти</button>
            </form>
            
            <form id="registerForm">
                <div class="form-group">
                    <input type="text" name="full_name" placeholder="Ваше полное имя" required>
                </div>
                
                <div class="form-group">
                    <input type="email" name="email" placeholder="email@example.com" required>
                </div>
                
                <div class="form-group">
                    <input type="password" name="password" placeholder="Создайте пароль" required>
                </div>
                
                <div class="form-group">
                    <input type="password" name="confirm_password" placeholder="Подтвердите пароль" required>
                </div>
                
                <button type="submit" class="btn">Зарегистрироваться</button>
            </form>
            
            <div class="form-toggle">
                <span id="toggleText">Нет аккаунта?</span>
                <a id="toggleLink">Зарегистрироваться!</a>
            </div>
            
            <div class="divider">
                <span>или</span>
            </div>
            
            <div class="form-footer">
                <p>Тестовые аккаунты:</p>
                <p>Админ: admin@system.com / admin123</p>
                <p>Преподаватель: teacher@system.com / teacher123</p>
                <p>Студент: student@system.com / student123</p>
            </div>
        </div>
    </div>

    <script>
        // Переключение между формами входа и регистрации
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');
        const toggleLink = document.getElementById('toggleLink');
        const toggleText = document.getElementById('toggleText');
        const formTitle = document.getElementById('formTitle');
        const formSubtitle = document.getElementById('formSubtitle');
        const errorMessage = document.getElementById('errorMessage');
        const successMessage = document.getElementById('successMessage');
        
        toggleLink.addEventListener('click', function() {
            if (loginForm.style.display === 'none') {
                // Переключаемся на форму входа
                loginForm.style.display = 'block';
                registerForm.style.display = 'none';
                formTitle.textContent = 'Добро пожаловать';
                formSubtitle.textContent = 'Войдите в систему для продолжения';
                toggleText.textContent = "Нет аккаунта?";
                toggleLink.textContent = 'Зарегистрироваться!';
            } else {
                // Переключаемся на форму регистрации
                loginForm.style.display = 'none';
                registerForm.style.display = 'block';
                formTitle.textContent = 'Создание аккаунта';
                formSubtitle.textContent = 'Зарегистрируйтесь для начала работы';
                toggleText.textContent = 'Уже есть аккаунт?';
                toggleLink.textContent = 'Войти!';
            }
        });
        
        // AJAX обработка форм
        function handleFormSubmit(form, url) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(form);
                
                fetch(url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        successMessage.textContent = data.message;
                        successMessage.style.display = 'block';
                        errorMessage.style.display = 'none';
                        
                        if (data.redirect) {
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 1000);
                        }
                    } else {
                        errorMessage.textContent = data.message;
                        errorMessage.style.display = 'block';
                        successMessage.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    errorMessage.textContent = 'Произошла ошибка. Пожалуйста, попробуйте снова.';
                    errorMessage.style.display = 'block';
                });
            });
        }
        
        handleFormSubmit(loginForm, 'login.php');
        handleFormSubmit(registerForm, 'register.php');
    </script>
</body>
</html>