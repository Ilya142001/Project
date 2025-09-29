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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Montserrat', sans-serif;
        }
        
        body {
            background: url('/uploads/fon/FON.jpg') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: -1;
        }
        
        /* Анимированные частицы фона */
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
            animation: float 8s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            50% {
                transform: translateY(-100px) translateX(20px);
            }
        }
        
        .container {
            width: 100%;
            max-width: 450px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            position: relative;
            transform: translateY(30px);
            opacity: 0;
            animation: slideUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-height: 90vh;
            overflow-y: auto;
            margin: 20px 0;
            scrollbar-width: none;
            -ms-overflow-style: none;
            transition: all 0.5s ease;
        }

        .container::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
            background: transparent;
        }

        .container {
            -webkit-overflow-scrolling: touch;
        }
        
        @keyframes slideUp {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
        }
        
        .header {
            padding: 30px 20px 20px;
            text-align: center;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { transform: rotate(45deg) translateX(-100%); }
            50% { transform: rotate(45deg) translateX(100%); }
        }
        
        .title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.8s ease 0.3s both;
        }
        
        .subtitle {
            font-size: 14px;
            font-weight: 400;
            opacity: 0.9;
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.8s ease 0.5s both;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-container {
            padding: 40px 30px 30px;
        }
        
        .form-group {
            margin-bottom: 15px;
            position: relative;
            animation: fadeInUp 0.8s ease 0.7s both;
        }
        
        .form-group:nth-child(2) {
            animation-delay: 0.8s;
        }
        
        .form-group:nth-child(3) {
            animation-delay: 0.9s;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            transform: translateX(-10px);
            opacity: 0;
            animation: slideInLeft 0.5s ease 0.5s forwards;
        }
        
        @keyframes slideInLeft {
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #eef2f7;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: #2c3e50;
            background: #f9fbfd;
            transform: translateX(-20px);
            opacity: 0;
            animation: slideInRight 0.5s ease 0.5s forwards;
        }
        
        @keyframes slideInRight {
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.15);
            transform: translateY(-2px);
        }
        
        .form-group input::placeholder {
            color: #a0a7b0;
        }
        
        .form-group i {
            position: absolute;
            right: 18px;
            top: 43px;
            color: #a0a7b0;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus + i {
            color: #3498db;
            transform: scale(1.1);
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 25px;
            animation: fadeInUp 0.8s ease 1s both;
        }
        
        .forgot-password a {
            color: #3498db;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            position: relative;
        }
        
        .forgot-password a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: #3498db;
            transition: width 0.3s ease;
        }
        
        .forgot-password a:hover {
            color: #2980b9;
        }
        
        .forgot-password a:hover::after {
            width: 100%;
        }
        
        .forgot-password a i {
            margin-left: 8px;
            font-size: 12px;
            transition: transform 0.3s ease;
        }
        
        .forgot-password a:hover i {
            transform: translateX(3px);
        }
        
        .btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.8s ease 1.1s both;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.6s;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(52, 152, 219, 0.4);
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn:active {
            transform: translateY(-1px);
        }
        
        .btn-loading {
            pointer-events: none;
            opacity: 0.8;
        }
        
        .btn-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .form-footer {
            text-align: center;
            margin-top: 25px;
            color: #7f8c8d;
            font-size: 14px;
            padding: 20px 15px;
            border-top: 1px solid #eee;
            background: #f9fbfd;
            border-radius: 0 0 20px 20px;
            animation: fadeInUp 0.8s ease 1.2s both;
        }
        
        .form-footer p {
            margin-bottom: 8px;
            opacity: 0;
            animation: fadeInUp 0.5s ease 1.4s forwards;
        }
        
        .form-footer p:nth-child(2) { animation-delay: 1.5s; }
        .form-footer p:nth-child(3) { animation-delay: 1.6s; }
        .form-footer p:nth-child(4) { animation-delay: 1.7s; }
        
        .form-footer p:last-child {
            margin-bottom: 0;
        }
        
        .form-footer p:nth-child(2),
        .form-footer p:nth-child(3),
        .form-footer p:nth-child(4) {
            font-size: 13px;
            color: #5d6d7e;
            padding: 8px;
            background: rgba(236, 240, 241, 0.5);
            border-radius: 8px;
            margin-bottom: 8px;
        }
        
        .error-message {
            color: #c0392b;
            background: #f9ebea;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: none;
            font-size: 14px;
            font-weight: 500;
            border-left: 4px solid #e74c3c;
            animation: shake 0.5s ease, fadeInUp 0.5s ease;
        }
        
        .success-message {
            color: #27ae60;
            background: #eafaf1;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: none;
            font-size: 14px;
            font-weight: 500;
            border-left: 4px solid #2ecc71;
            animation: fadeInUp 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }
        
        .form-toggle {
            text-align: center;
            margin-top: 25px;
            padding: 20px;
            background: #f9fbfd;
            border-radius: 12px;
            border: 1px solid #eef2f7;
            animation: fadeInUp 0.8s ease 1.3s both;
        }
        
        .form-toggle span {
            color: #5d6d7e;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .form-toggle a {
            color: #3498db;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.3s ease;
            display: inline-block;
            font-size: 14px;
            position: relative;
        }
        
        .form-toggle a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: #3498db;
            transition: width 0.3s ease;
        }
        
        .form-toggle a:hover {
            color: #2980b9;
        }
        
        .form-toggle a:hover::after {
            width: 100%;
        }
        
        /* Анимации для переключения форм */
        .form-switch-enter {
            animation: formSwitchEnter 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        .form-switch-exit {
            animation: formSwitchExit 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        @keyframes formSwitchEnter {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        @keyframes formSwitchExit {
            from {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
            to {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
        }
        
        #registerForm {
            display: none;
        }

        /* Анимация приветствия */
        .welcome-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s ease;
        }

        .welcome-overlay.active {
            opacity: 1;
            visibility: visible;
            animation: welcomeFadeIn 0.8s ease;
        }

        .welcome-content {
            text-align: center;
            color: white;
            transform: scale(0.8);
            opacity: 0;
            transition: all 0.5s ease 0.2s;
        }

        .welcome-overlay.active .welcome-content {
            transform: scale(1);
            opacity: 1;
        }

        .welcome-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 1s ease infinite alternate;
        }

        .welcome-title {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            animation: pulse 2s ease infinite;
        }

        .welcome-subtitle {
            font-size: 20px;
            opacity: 0.9;
            margin-bottom: 30px;
        }

        .welcome-loader {
            width: 60px;
            height: 60px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            margin: 0 auto;
            animation: spin 1s linear infinite;
        }

        @keyframes bounce {
            from { transform: translateY(0); }
            to { transform: translateY(-20px); }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @keyframes welcomeFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @media (max-width: 576px) {
            .container {
                max-width: 100%;
                border-radius: 16px;
                margin: 10px;
            }
            
            .header {
                padding: 30px 20px 25px;
            }
            
            .title {
                font-size: 24px;
            }
            
            .form-container {
                padding: 20px 30px;
            }
            
            .btn:hover {
                transform: none;
            }

            .welcome-title {
                font-size: 32px;
            }
            
            .welcome-subtitle {
                font-size: 16px;
            }
            
            .welcome-icon {
                font-size: 60px;
            }
        }
    </style>
</head>
<body>
    <!-- Анимированные частицы фона -->
    <div class="particles" id="particles"></div>
    
    <!-- Анимация приветствия -->
    <div class="welcome-overlay" id="welcomeOverlay">
        <div class="welcome-content">
            <div class="welcome-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h1 class="welcome-title">Добро пожаловать!</h1>
            <p class="welcome-subtitle">Успешная авторизация</p>
            <div class="welcome-loader"></div>
        </div>
    </div>
    
    <div class="container" id="mainContainer">
        <div class="header">
            <h1 class="title" id="formTitle">MEMBER LOGIN</h1>
            <p class="subtitle" id="formSubtitle">Enter your credentials to access your account</p>
        </div>
        
        <div class="form-container">
            <div class="error-message" id="errorMessage"></div>
            <div class="success-message" id="successMessage"></div>
            
            <form id="loginForm">
                <div class="form-group">
                    <label for="email">Username</label>
                    <input type="text" id="email" name="email" placeholder="Enter your username" required>
                    <i class="fas fa-user"></i>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <i class="fas fa-lock"></i>
                </div>
                
                <div class="forgot-password">
                    <a href="#">Forget Password? Click Here <i class="fas fa-arrow-right"></i></a>
                </div>
                
                <button type="submit" class="btn">LOGIN</button>
            </form>
            
            <form id="registerForm">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Your full name" required>
                    <i class="fas fa-user"></i>
                </div>
                
                <div class="form-group">
                    <label for="reg_email">Email</label>
                    <input type="email" id="reg_email" name="email" placeholder="email@example.com" required>
                    <i class="fas fa-envelope"></i>
                </div>
                
                <div class="form-group">
                    <label for="reg_password">Password</label>
                    <input type="password" id="reg_password" name="password" placeholder="Create a password" required>
                    <i class="fas fa-lock"></i>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                    <i class="fas fa-lock"></i>
                </div>
                
                <button type="submit" class="btn">REGISTER</button>
            </form>
            
            <div class="form-toggle">
                <span id="toggleText">Don't have an account?</span>
                <a id="toggleLink">REGISTER</a>
            </div>
        
        </div>
    </div>

    <script>
        // Создаем анимированные частицы фона
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 15;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.top = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 8 + 's';
                particle.style.animationDuration = (6 + Math.random() * 4) + 's';
                particlesContainer.appendChild(particle);
            }
        }

        // Переключение между формами входа и регистрации
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');
        const toggleLink = document.getElementById('toggleLink');
        const toggleText = document.getElementById('toggleText');
        const formTitle = document.getElementById('formTitle');
        const formSubtitle = document.getElementById('formSubtitle');
        const errorMessage = document.getElementById('errorMessage');
        const successMessage = document.getElementById('successMessage');
        const mainContainer = document.getElementById('mainContainer');
        
        function switchForm(isToRegister) {
            const currentForm = isToRegister ? loginForm : registerForm;
            const newForm = isToRegister ? registerForm : loginForm;
            
            // Анимация исчезновения текущей формы
            currentForm.style.animation = 'formSwitchExit 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards';
            
            setTimeout(() => {
                currentForm.style.display = 'none';
                newForm.style.display = 'block';
                
                // Обновляем тексты
                if (isToRegister) {
                    formTitle.textContent = 'CREATE ACCOUNT';
                    formSubtitle.textContent = 'Register for a new account';
                    toggleText.textContent = 'Already have an account?';
                    toggleLink.textContent = 'LOGIN';
                } else {
                    formTitle.textContent = 'MEMBER LOGIN';
                    formSubtitle.textContent = 'Enter your credentials to access your account';
                    toggleText.textContent = "Don't have an account?";
                    toggleLink.textContent = 'REGISTER';
                }
                
                // Анимация появления новой формы
                newForm.style.animation = 'formSwitchEnter 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                
            }, 250);
        }
        
        toggleLink.addEventListener('click', function() {
            const isToRegister = loginForm.style.display !== 'none';
            switchForm(isToRegister);
        });

        // Функция показа анимации приветствия
        function showWelcomeAnimation() {
            const welcomeOverlay = document.getElementById('welcomeOverlay');
            
            // Скрываем основную форму
            mainContainer.style.opacity = '0';
            mainContainer.style.visibility = 'hidden';
            
            // Показываем анимацию приветствия
            welcomeOverlay.classList.add('active');
        }
        
        // AJAX обработка форм
        function handleFormSubmit(form, url) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const submitBtn = form.querySelector('.btn');
                const originalText = submitBtn.textContent;
                
                // Показываем анимацию загрузки
                submitBtn.classList.add('btn-loading');
                submitBtn.textContent = '';
                
                const formData = new FormData(form);
                
                fetch(url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Возвращаем кнопку в исходное состояние
                    submitBtn.classList.remove('btn-loading');
                    submitBtn.textContent = originalText;
                    
                    if (data.success) {
                        successMessage.textContent = data.message;
                        successMessage.style.display = 'block';
                        errorMessage.style.display = 'none';
                        
                        // Показываем анимацию приветствия
                        showWelcomeAnimation();
                        
                        if (data.redirect) {
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 3500); // Увеличиваем задержку для полного показа анимации
                        }
                    } else {
                        errorMessage.textContent = data.message;
                        errorMessage.style.display = 'block';
                        successMessage.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    submitBtn.classList.remove('btn-loading');
                    submitBtn.textContent = originalText;
                    errorMessage.textContent = 'An error occurred. Please try again.';
                    errorMessage.style.display = 'block';
                });
            });
        }
        
        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            handleFormSubmit(loginForm, 'login.php');
            handleFormSubmit(registerForm, 'register.php');
        });
    </script>
</body>
</html>