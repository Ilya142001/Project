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
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
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
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
        }
        
        .header {
            padding: 35px 30px 25px;
            text-align: center;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            position: relative;
            border-bottom: none;
        }
        
        .title {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        
        .subtitle {
            font-size: 14px;
            font-weight: 400;
            opacity: 0.9;
        }
        
        .form-container {
            padding: 35px 30px 30px;
        }
        
        .form-group {
            margin-bottom: 22px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group input {
            width: 100%;
            padding: 16px 18px;
            border: 2px solid #eef2f7;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s ease;
            color: #2c3e50;
            background: #f9fbfd;
        }
        
        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
        }
        
        .form-group input::placeholder {
            color: #a0a7b0;
        }
        
        .form-group i {
            position: absolute;
            right: 15px;
            top: 43px;
            color: #a0a7b0;
            font-size: 16px;
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 25px;
        }
        
        .forgot-password a {
            color: #3498db;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }
        
        .forgot-password a:hover {
            color: #2980b9;
            text-decoration: underline;
        }
        
        .forgot-password a i {
            margin-left: 5px;
            font-size: 12px;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            position: relative;
            overflow: hidden;
        }
        
        .btn::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(52, 152, 219, 0.3);
        }
        
        .btn:hover::after {
            left: 100%;
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .form-footer {
            text-align: center;
            margin-top: 25px;
            color: #7f8c8d;
            font-size: 14px;
            padding: 20px 15px;
            border-top: 1px solid #eee;
            background: #f9fbfd;
            border-radius: 0 0 15px 15px;
        }
        
        .form-footer p {
            margin-bottom: 8px;
        }
        
        .form-footer p:last-child {
            margin-bottom: 0;
        }
        
        .form-footer p:nth-child(2),
        .form-footer p:nth-child(3),
        .form-footer p:nth-child(4) {
            font-size: 13px;
            color: #5d6d7e;
            padding: 4px;
            background: rgba(236, 240, 241, 0.5);
            border-radius: 4px;
            margin-bottom: 5px;
        }
        
        .error-message {
            color: #c0392b;
            background: #f9ebea;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 22px;
            display: none;
            font-size: 14px;
            font-weight: 500;
            border-left: 4px solid #e74c3c;
            animation: shake 0.5s ease;
        }
        
        .success-message {
            color: #27ae60;
            background: #eafaf1;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 22px;
            display: none;
            font-size: 14px;
            font-weight: 500;
            border-left: 4px solid #2ecc71;
            animation: fadeIn 0.5s ease;
        }
        
        .form-toggle {
            text-align: center;
            margin-top: 25px;
            padding: 18px;
            background: #f9fbfd;
            border-radius: 8px;
            border: 1px solid #eef2f7;
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
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        #registerForm {
            display: none;
        }
        
        @media (max-width: 576px) {
            .container {
                max-width: 100%;
                border-radius: 12px;
            }
            
            .header {
                padding: 25px 20px 20px;
            }
            
            .title {
                font-size: 22px;
            }
            
            .form-container {
                padding: 25px 20px;
            }
            
            .btn:hover {
                transform: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
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
                formTitle.textContent = 'MEMBER LOGIN';
                formSubtitle.textContent = 'Enter your credentials to access your account';
                toggleText.textContent = "Don't have an account?";
                toggleLink.textContent = 'REGISTER';
            } else {
                // Переключаемся на форму регистрации
                loginForm.style.display = 'none';
                registerForm.style.display = 'block';
                formTitle.textContent = 'CREATE ACCOUNT';
                formSubtitle.textContent = 'Register for a new account';
                toggleText.textContent = 'Already have an account?';
                toggleLink.textContent = 'LOGIN';
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
                    errorMessage.textContent = 'An error occurred. Please try again.';
                    errorMessage.style.display = 'block';
                });
            });
        }
        
        handleFormSubmit(loginForm, 'login.php');
        handleFormSubmit(registerForm, 'register.php');
    </script>
</body>
</html>