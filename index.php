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
            border-radius: 10px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            position: relative;
        }
        
        .header {
            padding: 30px 30px 20px;
            text-align: center;
            background: white;
            color: #2c3e50;
            position: relative;
            border-bottom: 1px solid #eee;
        }
        
        .title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .subtitle {
            font-size: 14px;
            font-weight: 400;
            color: #7f8c8d;
        }
        
        .form-container {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .form-group input {
            width: 100%;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s ease;
            color: #2c3e50;
            background: #f9f9f9;
        }
        
        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            background: white;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .form-group input::placeholder {
            color: #95a5a6;
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 25px;
        }
        
        .forgot-password a {
            color: #3498db;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .forgot-password a:hover {
            color: #2980b9;
            text-decoration: underline;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .form-footer {
            text-align: center;
            margin-top: 25px;
            color: #7f8c8d;
            font-size: 14px;
            padding: 15px;
            border-top: 1px solid #eee;
        }
        
        .form-footer a {
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .form-footer a:hover {
            color: #2980b9;
            text-decoration: underline;
        }
        
        .error-message {
            color: #e74c3c;
            background: #fadbd8;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
            font-size: 14px;
            animation: shake 0.5s ease;
        }
        
        .success-message {
            color: #27ae60;
            background: #d5f4e6;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
            font-size: 14px;
            animation: fadeIn 0.5s ease;
        }
        
        .form-toggle {
            text-align: center;
            margin-top: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
            border: 1px solid #eee;
        }
        
        .form-toggle span {
            color: #7f8c8d;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .form-toggle a {
            color: #3498db;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-block;
            font-size: 14px;
        }
        
        .form-toggle a:hover {
            color: #2980b9;
            text-decoration: underline;
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
            }
            
            .header {
                padding: 25px 20px 15px;
            }
            
            .title {
                font-size: 20px;
            }
            
            .form-container {
                padding: 25px 20px;
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
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                
                <div class="forgot-password">
                    <a href="#">Forget Password? Click Here</a>
                </div>
                
                <button type="submit" class="btn">LOGIN</button>
            </form>
            
            <form id="registerForm">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Your full name" required>
                </div>
                
                <div class="form-group">
                    <label for="reg_email">Email</label>
                    <input type="email" id="reg_email" name="email" placeholder="email@example.com" required>
                </div>
                
                <div class="form-group">
                    <label for="reg_password">Password</label>
                    <input type="password" id="reg_password" name="password" placeholder="Create a password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
                
                <button type="submit" class="btn">REGISTER</button>
            </form>
            
            <div class="form-toggle">
                <span id="toggleText">Don't have an account?</span>
                <a id="toggleLink">REGISTER</a>
            </div>
            
            <div class="form-footer">
                <p>Test accounts:</p>
                <p>Admin: admin@system.com / admin123</p>
                <p>Teacher: teacher@system.com / teacher123</p>
                <p>Student: student@system.com / student123</p>
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