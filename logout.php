<?php
include 'config.php';

// Проверяем, был ли передан параметр подтверждения
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    // Уничтожаем сессию
    session_destroy();
    // Перенаправляем на главную страницу
    header("Location: index.php");
    exit;
}

// Если параметр confirm не равен 'yes', показываем страницу с JavaScript подтверждением
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выход из системы</title>
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
            padding: 40px 30px 30px;
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
        
        .logout-content {
            padding: 40px 30px 30px;
            text-align: center;
        }
        
        .logout-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 2s ease-in-out infinite;
            display: inline-block;
            background: linear-gradient(135deg, #3498db, #2980b9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }
        
        .logout-message {
            font-size: 16px;
            margin-bottom: 30px;
            color: #2c3e50;
            line-height: 1.6;
            animation: fadeInUp 0.8s ease 0.7s both;
        }
        
        .confirmation-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    animation: fadeInUp 0.8s ease 0.9s both;
    margin-top: 30px; /* Больший отступ */
}
        
        .btn {
            padding: 16px 32px;
            border-radius: 12px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.6s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(231, 76, 60, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(231, 76, 60, 0.4);
        }
        
        .btn-outline {
            background: transparent;
            color: #7f8c8d;
            border: 2px solid #eef2f7;
        }
        
        .btn-outline:hover {
            background: #f9fbfd;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .loading-dots {
            display: none;
            gap: 4px;
            margin-top: 20px;
            justify-content: center;
            animation: fadeInUp 0.8s ease both;
        }
        
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #3498db;
            animation: dotPulse 1.4s ease-in-out infinite both;
        }
        
        .dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes dotPulse {
            0%, 80%, 100% {
                transform: scale(0.8);
                opacity: 0.5;
            }
            40% {
                transform: scale(1.2);
                opacity: 1;
            }
        }
        
        .farewell-message {
            font-size: 14px;
            margin-top: 20px;
            padding: 15px;
            background: rgba(52, 152, 219, 0.1);
            border-radius: 12px;
            border-left: 4px solid #3498db;
            color: #2c3e50;
            animation: fadeInUp 0.8s ease 1.1s both;
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
            
            .logout-content {
                padding: 30px 20px;
            }
            
            .confirmation-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .logout-icon {
                font-size: 60px;
            }
        }
    </style>
</head>
<body>
    <!-- Анимированные частицы фона -->
    <div class="particles" id="particles"></div>
    
    <div class="container">
        <div class="header">
            <h1 class="title">Выход из системы</h1>
            <p class="subtitle">Подтвердите действие</p>
        </div>
        
        <div class="logout-content">
            <div class="logout-icon">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            
            <p class="logout-message">
                Вы уверены, что хотите выйти из системы?<br>
                Для продолжения работы потребуется повторная авторизация.
            </p>

            <div class="farewell-message">
                <i class="fas fa-heart" style="color: #e74c3c; margin-right: 8px;"></i>
                Спасибо, что воспользовались нашей системой! До новых встреч!
            </div>

            <div class="confirmation-buttons">
                <button class="btn btn-primary" onclick="confirmLogout()">
                    <i class="fas fa-sign-out-alt"></i>
                    Да, выйти
                </button>
                <button class="btn btn-outline" onclick="cancelLogout()">
                    <i class="fas fa-arrow-left"></i>
                    Отмена
                </button>
            </div>

            <div class="loading-dots" id="loadingDots">
                <div class="dot"></div>
                <div class="dot"></div>
                <div class="dot"></div>
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

        function confirmLogout() {
            const buttons = document.querySelector('.confirmation-buttons');
            const loadingDots = document.getElementById('loadingDots');
            const farewell = document.querySelector('.farewell-message');
            const logoutMessage = document.querySelector('.logout-message');
            
            // Анимация исчезновения элементов
            buttons.style.animation = 'fadeInUp 0.5s ease-out reverse both';
            farewell.style.animation = 'fadeInUp 0.5s ease-out reverse both';
            
            setTimeout(() => {
                buttons.style.display = 'none';
                farewell.style.display = 'none';
                
                // Обновляем сообщение
                logoutMessage.textContent = 'Выполняется выход из системы...';
                logoutMessage.style.color = '#3498db';
                
                // Показываем анимацию загрузки
                loadingDots.style.display = 'flex';
                
                // Задержка перед перенаправлением для демонстрации анимации
                setTimeout(() => {
                    window.location.href = 'logout.php?confirm=yes';
                }, 2000);
            }, 500);
        }

        function cancelLogout() {
            const container = document.querySelector('.container');
            
            // Анимация исчезновения контейнера
            container.style.animation = 'slideUp 0.5s ease-out reverse both';
            
            setTimeout(() => {
                window.history.back();
            }, 500);
        }

        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
        });
    </script>
</body>
</html>