<?php
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $password !== '') {

        $stmt = $conn->prepare("
            SELECT id, username, password, full_name, role
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {

                if (password_verify($password, $row['password'])) {

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['full_name'] = $row['full_name'];
                    $_SESSION['role'] = $row['role'];

                    header("Location: dashboard.php");
                    exit();
                }
            }

            $stmt->close();
        }
    }

    $error = "Invalid username or password.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description"
        content="MSPANAY Distribution Inc. — Secure Picklist Monitoring and Workflow Tracking System.">

    <title>MSPANAY Distribution Inc. | Picklist Monitoring System</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Montserrat:wght@700;800;900&display=swap"
        rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>

        /* ================= RESET ================= */

        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --navy-900: #081426;
            --navy-800: #0c1f3a;
            --navy-700: #123055;

            --blue: #3498db;
            --blue-light: #74b9ff;

            --red: #ff4757;

            --text: rgba(255,255,255,0.75);
            --white: #ffffff;

            --glass: rgba(255,255,255,0.06);
            --glass-strong: rgba(255,255,255,0.1);
            --glass-border: rgba(255,255,255,0.14);

            --gradient: linear-gradient(135deg, var(--red), var(--blue));

            --shadow-lg: 0 24px 60px rgba(2,10,23,0.45);

            --radius: 16px;

            --transition: 0.35s cubic-bezier(0.22,1,0.36,1);

            --edge: clamp(0.9rem,3vw,1.8rem);
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Poppins','Segoe UI',sans-serif;

            background:
                radial-gradient(1100px circle at 85% -10%, rgba(52,152,219,0.28), transparent 45%),
                radial-gradient(900px circle at -10% 40%, rgba(255,71,87,0.16), transparent 45%),
                linear-gradient(160deg,var(--navy-900),var(--navy-800) 55%,var(--navy-700));

            background-attachment: fixed;

            color: var(--text);

            line-height: 1.5;

            font-size: 0.95rem;

            overflow-x: hidden;

            min-height: 100vh;

            display: flex;
            flex-direction: column;
        }

        ::selection {
            background: var(--blue);
            color: white;
        }

        a:focus-visible,
        button:focus-visible {
            outline: 3px solid var(--blue-light);
            outline-offset: 3px;
        }


        /* ================= HEADER ================= */

        .site-header {
            position: sticky;
            top: 0;
            z-index: 100;

            flex-shrink: 0;

            background: rgba(8,20,38,0.75);

            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);

            transition: var(--transition);
        }

        .site-header.scrolled {
            background: rgba(8,20,38,0.95);
            box-shadow: 0 10px 30px rgba(2,10,23,0.35);
        }

        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 1rem;

            width: 100%;

            padding: 0.6rem var(--edge);
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;

            text-decoration: none;
        }

        .logo-icon {
            width: 38px;
            height: 38px;

            display: grid;
            place-items: center;

            border-radius: 10px;

            background: var(--gradient);

            color: white;

            font-size: 0.95rem;

            box-shadow: 0 8px 20px rgba(52,152,219,0.35);

            flex-shrink: 0;
        }

        .logo-text {
            display: flex;
            flex-direction: column;

            font-family: 'Montserrat',sans-serif;

            font-size: 1.15rem;

            font-weight: 900;

            letter-spacing: 1.2px;

            text-transform: uppercase;

            color: white;

            line-height: 1.1;
        }

        .logo-text small {
            font-size: 0.62rem;

            font-weight: 600;

            letter-spacing: 2.5px;

            color: var(--blue-light);
        }

        .header-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-link {
            color: var(--text);

            text-decoration: none;

            font-weight: 500;

            font-size: 0.88rem;

            background: none;
            border: none;

            cursor: pointer;

            font-family: inherit;

            transition: 0.25s;
        }

        .nav-link:hover {
            color: var(--blue-light);
        }

        .login-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;

            background: var(--gradient);

            color: white;

            padding: 0.45rem 1.1rem;

            border-radius: 999px;

            text-decoration: none;

            font-weight: 600;

            font-size: 0.85rem;

            border: none;

            cursor: pointer;

            font-family: inherit;

            box-shadow: 0 8px 20px rgba(255,71,87,0.3);

            transition: var(--transition);
        }

        .login-btn:hover {
            transform: translateY(-2px);

            box-shadow: 0 12px 28px rgba(52,152,219,0.45);
        }


        /* ================= MAIN ================= */

        main {
            flex: 1;

            display: flex;
            flex-direction: column;
        }


        /* ================= HERO ================= */

        .hero {
            position: relative;

            flex: 1;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 2rem var(--edge);

            overflow: hidden;

            text-align: center;

            min-height: 460px;
        }

        .orb {
            position: absolute;

            border-radius: 50%;

            filter: blur(80px);

            opacity: 0.45;

            pointer-events: none;

            animation: orbFloat 12s ease-in-out infinite;
        }

        .orb-1 {
            width: 340px;
            height: 340px;

            background: rgba(52,152,219,0.55);

            top: -80px;
            right: -60px;
        }

        .orb-2 {
            width: 280px;
            height: 280px;

            background: rgba(255,71,87,0.45);

            bottom: -60px;
            left: -50px;

            animation-delay: -4s;
        }

        .orb-3 {
            width: 180px;
            height: 180px;

            background: rgba(116,185,255,0.35);

            top: 40%;
            left: 12%;

            animation-delay: -8s;
        }

        @keyframes orbFloat {
            0%,100% {
                transform: translate(0,0) scale(1);
            }

            33% {
                transform: translate(30px,-24px) scale(1.08);
            }

            66% {
                transform: translate(-24px,20px) scale(0.94);
            }
        }

        .hero::before {
            content: '';

            position: absolute;

            inset: 0;

            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px,transparent 1px),
                linear-gradient(90deg,rgba(255,255,255,0.035) 1px,transparent 1px);

            background-size: 48px 48px;

            mask-image: radial-gradient(ellipse at center,#000 30%,transparent 75%);

            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 1;

            display: flex;
            flex-direction: column;
            align-items: center;

            gap: 0.8rem;

            max-width: 820px;

            width: 100%;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;

            padding: 0.4rem 1rem;

            border-radius: 999px;

            background: var(--glass);

            border: 1px solid rgba(116,185,255,0.4);

            backdrop-filter: blur(12px);

            font-size: 0.78rem;

            font-weight: 500;

            letter-spacing: 1.5px;

            text-transform: uppercase;

            color: var(--blue-light);

            animation: riseIn 0.7s ease 0.1s both;
        }

        .hero-badge i {
            font-size: 0.7rem;
        }


        /* ================= OFFICIAL COMPANY LOGO ================= */

        .hero-logo {
            display: block;

            width: clamp(220px,45vw,400px);

            max-width: 100%;

            height: auto;

            object-fit: contain;

            margin: 0.5rem auto;

            border-radius: 12px;

            box-shadow: 0 15px 45px rgba(0,0,0,0.25);

            animation: riseIn 0.8s ease 0.2s both;

            transition: transform 0.4s ease, box-shadow 0.4s ease;
        }

        .hero-logo:hover {
            transform: translateY(-5px) scale(1.02);

            box-shadow: 0 20px 55px rgba(52,152,219,0.25);
        }


        /* ================= HERO DESCRIPTION ================= */

        .hero-tagline {
            margin-top: 0.6rem;

            max-width: 540px;

            font-size: clamp(0.85rem,2vw,1rem);

            font-weight: 300;

            color: var(--text);

            animation: riseIn 0.7s ease 0.55s both;
        }

        .hero-tagline strong {
            color: var(--blue-light);

            font-weight: 600;
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(26px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }


        /* ================= HERO BUTTONS ================= */

        .hero-actions {
            flex-shrink: 0;

            display: flex;
            justify-content: center;

            gap: 1rem;

            flex-wrap: wrap;

            padding: 0 1rem 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;

            padding: 0.5rem 1.2rem;

            border-radius: 999px;

            font-weight: 600;

            font-size: 0.88rem;

            text-decoration: none;

            border: none;

            cursor: pointer;

            font-family: inherit;

            transition: var(--transition);
        }

        .btn-ghost {
            background: var(--glass);

            color: white;

            border: 1px solid var(--glass-border);
        }

        .btn-ghost:hover {
            background: var(--glass-strong);

            transform: translateY(-3px);
        }


        /* ================= ABOUT US ================= */

        #values {
            scroll-margin-top: 80px;

            display: grid;

            grid-template-columns: repeat(auto-fit,minmax(280px,1fr));

            gap: 1rem;

            align-items: start;

            width: 100%;

            padding: 0 var(--edge) 2rem;

            animation: fadeSlide 0.4s ease;
        }

        #values.hidden {
            display: none;
        }

        @keyframes fadeSlide {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .value-card {
            position: relative;

            background: var(--glass);

            border-radius: var(--radius);

            padding: 1.2rem 1rem;

            backdrop-filter: blur(12px);

            overflow: hidden;

            text-align: left;

            transition: var(--transition);
        }

        .value-card::before {
            content: '';

            position: absolute;

            inset: 0 0 auto 0;

            height: 4px;

            background: var(--accent);
        }

        .value-card:nth-child(1) {
            --accent: var(--red);
        }

        .value-card:nth-child(2) {
            --accent: var(--blue);
        }

        .value-card:nth-child(3) {
            --accent: var(--blue-light);
        }

        .value-card:hover {
            transform: translateY(-6px);

            background: var(--glass-strong);

            box-shadow: var(--shadow-lg);
        }

        .value-icon {
            width: 36px;
            height: 36px;

            display: grid;
            place-items: center;

            border-radius: 10px;

            font-size: 0.95rem;

            color: white;

            background: var(--accent);

            margin-bottom: 0.6rem;
        }

        .value-card h2 {
            font-size: 1.05rem;

            font-weight: 700;

            color: var(--accent);

            margin-bottom: 0.4rem;
        }

        .value-card p {
            font-size: 0.85rem;

            margin-bottom: 0.7rem;
        }

        .quote {
            font-style: italic;

            font-size: 0.8rem;

            padding: 0.6rem 0.8rem;

            border-radius: 10px;

            border-left: 3px solid var(--accent);

            color: rgba(255,255,255,0.85);

            margin-top: 0.7rem;
        }

        .value-card:nth-child(1) .quote {
            background: rgba(255,71,87,0.12);
        }

        .value-card:nth-child(2) .quote {
            background: rgba(52,152,219,0.12);
        }

        .value-card:nth-child(3) .quote {
            background: rgba(116,185,255,0.1);
        }

        .quote-author {
            margin-top: 0.3rem;

            text-align: right;

            font-weight: 600;

            font-size: 0.75rem;

            font-style: normal;

            color: var(--accent);
        }

        .values-list {
            list-style: none;

            margin-top: 0.7rem;
        }

        .values-list li {
            position: relative;

            padding-left: 1.2rem;

            margin-bottom: 0.45rem;

            font-size: 0.82rem;
        }

        .values-list li::before {
            content: '';

            position: absolute;

            left: 0;
            top: 0.55em;

            width: 6px;
            height: 6px;

            border-radius: 50%;

            background: var(--accent);
        }

        .values-list strong {
            color: white;
        }


        /* ================= FOOTER ================= */

        .site-footer {
            flex-shrink: 0;

            background: rgba(8,20,38,0.7);

            backdrop-filter: blur(10px);

            padding: 1rem var(--edge) 0.6rem;
        }

        .footer-grid {
            display: grid;

            grid-template-columns: repeat(auto-fit,minmax(180px,1fr));

            gap: 1rem;

            margin-bottom: 0.6rem;

            text-align: left;
        }

        .footer-brand {
            justify-self: start;
        }

        .footer-brand .logo-text {
            font-size: 0.95rem;
        }

        .footer-brand .logo-icon {
            width: 30px;
            height: 30px;

            font-size: 0.8rem;
        }

        .footer-brand p {
            font-size: 0.75rem;

            margin-top: 0.4rem;

            max-width: 300px;

            line-height: 1.5;
        }

        .footer-col h3 {
            color: white;

            font-size: 0.8rem;

            margin-bottom: 0.3rem;
        }

        .footer-col ul {
            list-style: none;
        }

        .footer-col a {
            color: var(--text);

            text-decoration: none;

            font-size: 0.76rem;

            display: inline-block;

            padding: 0.08rem 0;

            transition: 0.25s;
        }

        .footer-col a:hover {
            color: var(--blue-light);

            transform: translateX(4px);
        }

        .footer-bottom {
            padding-top: 0.5rem;

            text-align: center;

            font-size: 0.72rem;
        }


        /* ================= BACK TO TOP ================= */

        #toTop {
            position: fixed;

            right: 1.2rem;
            bottom: 1.2rem;

            width: 40px;
            height: 40px;

            border: none;

            border-radius: 50%;

            background: var(--gradient);

            color: white;

            font-size: 0.9rem;

            cursor: pointer;

            opacity: 0;
            visibility: hidden;

            transform: translateY(12px);

            transition: var(--transition);

            box-shadow: 0 10px 25px rgba(52,152,219,0.4);

            z-index: 100;
        }

        #toTop.visible {
            opacity: 1;
            visibility: visible;

            transform: translateY(0);
        }

        #toTop:hover {
            transform: translateY(-4px);
        }


        /* ================= LOGIN POPUP ================= */

        .login-popup {
            position: fixed;

            top: 70px;

            right: var(--edge);

            z-index: 300;

            width: 300px;

            max-width: calc(100vw - 2rem);

            background: rgba(12,31,58,0.97);

            border: 1px solid var(--glass-border);

            border-radius: 14px;

            padding: 1.1rem;

            box-shadow: var(--shadow-lg);

            overflow: hidden;

            opacity: 0;
            visibility: hidden;

            transform: translateY(-12px) scale(0.96);

            transform-origin: top right;

            transition: 0.3s ease;
        }

        .login-popup.open {
            opacity: 1;
            visibility: visible;

            transform: translateY(0) scale(1);
        }

        .login-popup::after {
            content: '';

            position: absolute;

            inset: 0 0 auto 0;

            height: 3px;

            background: var(--gradient);
        }

        .popup-head {
            display: flex;

            align-items: center;

            gap: 0.55rem;

            margin-bottom: 0.9rem;
        }

        .popup-head .p-icon {
            width: 34px;
            height: 34px;

            display: grid;
            place-items: center;

            border-radius: 9px;

            background: var(--gradient);

            color: white;

            font-size: 0.85rem;

            flex-shrink: 0;
        }

        .popup-head h2 {
            font-family: 'Montserrat',sans-serif;

            font-size: 1rem;

            font-weight: 800;

            color: white;
        }

        .popup-head p {
            font-size: 0.68rem;

            color: rgba(255,255,255,0.5);
        }

        .popup-close {
            margin-left: auto;

            width: 26px;
            height: 26px;

            display: grid;
            place-items: center;

            border: none;

            border-radius: 50%;

            background: var(--glass);

            color: var(--text);

            cursor: pointer;
        }

        .popup-close:hover {
            background: rgba(255,71,87,0.3);

            color: white;
        }

        .popup-input {
            position: relative;

            margin-bottom: 0.65rem;
        }

        .popup-input i {
            position: absolute;

            left: 0.8rem;
            top: 50%;

            transform: translateY(-50%);

            font-size: 0.75rem;

            color: rgba(255,255,255,0.4);

            pointer-events: none;
        }

        .popup-input input {
            width: 100%;

            padding: 0.65rem 0.8rem 0.65rem 2.2rem;

            border-radius: 9px;

            border: 1px solid var(--glass-border);

            background: rgba(255,255,255,0.06);

            color: white;

            font-family: inherit;

            font-size: 0.82rem;

            outline: none;

            transition: 0.25s;
        }

        .popup-input input::placeholder {
            color: rgba(255,255,255,0.35);
        }

        .popup-input input:focus {
            border-color: var(--blue-light);

            background: rgba(255,255,255,0.1);

            box-shadow: 0 0 0 3px rgba(52,152,219,0.25);
        }

        .popup-error {
            display: flex;

            align-items: center;

            gap: 0.4rem;

            background: rgba(255,71,87,0.12);

            border: 1px solid rgba(255,71,87,0.4);

            border-left: 3px solid var(--red);

            color: #ffd3d7;

            font-size: 0.72rem;

            padding: 0.5rem 0.6rem;

            border-radius: 8px;

            margin-bottom: 0.65rem;
        }

        .popup-submit {
            width: 100%;

            display: inline-flex;

            align-items: center;
            justify-content: center;

            gap: 0.4rem;

            background: var(--gradient);

            color: white;

            font-weight: 600;

            font-size: 0.82rem;

            padding: 0.65rem;

            border: none;

            border-radius: 9px;

            cursor: pointer;

            font-family: inherit;

            box-shadow: 0 8px 20px rgba(52,152,219,0.35);

            transition: var(--transition);
        }

        .popup-submit:hover {
            transform: translateY(-2px);

            box-shadow: 0 10px 24px rgba(255,71,87,0.35);
        }

        .popup-foot {
            margin-top: 0.7rem;

            text-align: center;

            font-size: 0.65rem;

            color: rgba(255,255,255,0.4);
        }

        .popup-foot i {
            color: var(--blue-light);

            margin-right: 0.25rem;
        }


        /* ================= RESPONSIVE ================= */

        @media (max-width: 560px) {

            .nav-link {
                display: none;
            }

            .header-inner {
                padding: 0.5rem 0.9rem;
            }

            .logo-icon {
                width: 32px;
                height: 32px;

                font-size: 0.85rem;
            }

            .logo-text {
                font-size: 1rem;

                letter-spacing: 1px;
            }

            .logo-text small {
                font-size: 0.55rem;

                letter-spacing: 2px;
            }

            .hero {
                padding-top: 1.5rem;

                min-height: 420px;
            }

            .hero-logo {
                width: min(280px,85vw);
            }

            .login-popup {
                right: 0.9rem;
            }

            #values {
                grid-template-columns: 1fr;
            }

            .footer-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto;
            }

            *, *::before, *::after {
                animation-duration: 0.01ms !important;

                transition-duration: 0.01ms !important;
            }
        }

    </style>

</head>

<body>

    <!-- ================= HEADER ================= -->

    <header class="site-header">

        <div class="header-inner">

            <a href="index.php" class="logo"
                aria-label="MSPANAY Distribution Inc. Home">

                <span class="logo-icon">
                    <i class="fa-solid fa-cubes"></i>
                </span>

                <span class="logo-text">
                    MSPANAY
                    <small>Distribution Inc.</small>
                </span>

            </a>

            <nav class="header-nav" aria-label="Main navigation">

                <a href="#values" class="nav-link" data-toggle-values>
                    About Us
                </a>

                <button type="button"
                    class="login-btn"
                    id="loginToggle"
                    aria-expanded="false"
                    aria-controls="loginPopup">

                    <i class="fa-solid fa-right-to-bracket"></i>
                    Login

                </button>

            </nav>

        </div>

    </header>


    <!-- ================= MAIN ================= -->

    <main>

        <!-- ================= HERO SECTION ================= -->

        <section class="hero" id="home">

            <span class="orb orb-1" aria-hidden="true"></span>
            <span class="orb orb-2" aria-hidden="true"></span>
            <span class="orb orb-3" aria-hidden="true"></span>

            <div class="hero-content">

                <span class="hero-badge">

                    <i class="fa-solid fa-cubes"></i>

                    Picklist Monitoring System

                </span>


                <!-- OFFICIAL MSPANAY LOGO -->

                <img
                    src="mspanay-logo.png"
                    alt="MSPANAY Distribution Inc. - Exclusive Distributor of Procter and Gamble"
                    class="hero-logo"
                    width="400"
                >


                <!-- COMPANY DESCRIPTION -->

                <p class="hero-tagline">

                    Reliable distribution and
                    <strong>picklist monitoring</strong> across
                    Western Visayas — built on honesty, integrity, and trust.

                </p>

            </div>

        </section>


        <!-- ================= LEARN MORE BUTTON ================= -->

        <div class="hero-actions">

            <button type="button"
                id="learnBtn"
                class="btn btn-ghost"
                aria-expanded="false"
                aria-controls="values">

                Learn More

                <i class="fa-solid fa-arrow-down"></i>

            </button>

        </div>


        <!-- ================= ABOUT US ================= -->

        <section id="values" class="hidden">

            <!-- OUR VISION -->

            <article class="value-card">

                <div class="value-icon">
                    <i class="fa-solid fa-eye"></i>
                </div>

                <h2>Our Vision</h2>

                <p>
                    We will be the most trusted service provider in Western
                    Visayas by being the most innovative and competent
                    organization, performing under the highest standards of
                    operational excellence, delivering employee satisfaction
                    and superior customer service.
                </p>

                <div class="quote">

                    "Where there is no vision, there is no hope."

                    <div class="quote-author">
                        — George Washington Carver
                    </div>

                </div>

            </article>


            <!-- OUR PURPOSE -->

            <article class="value-card">

                <div class="value-icon">
                    <i class="fa-solid fa-bullseye"></i>
                </div>

                <h2>Our Purpose</h2>

                <p>
                    We will provide products and services of superior quality
                    and value that improve the lives of the consumer. This
                    will reward us with leadership sales, profitable growth,
                    allowing our people, stakeholders, and the communities
                    in which we live and work to prosper.
                </p>

                <div class="quote">

                    "Your customer doesn't care how much you know until they
                    know how much you care."

                    <div class="quote-author">
                        — Damon Richards
                    </div>

                </div>

            </article>


            <!-- OUR WORK VALUES -->

            <article class="value-card">

                <div class="value-icon">
                    <i class="fa-solid fa-handshake-angle"></i>
                </div>

                <h2>Our Work Values</h2>

                <p>
                    The company believes that good values provide a strong
                    "backbone" to growing standards of ethical conduct and
                    behavior. These values are:
                </p>

                <ul class="values-list">

                    <li>
                        <strong>Honesty</strong> – being truthful and
                        "up front" with our co-workers, customers,
                        communities and shareholders.
                    </li>

                    <li>
                        <strong>Integrity</strong> – saying what we mean
                        and meaning what we say; delivering what we promise;
                        standing for what is right.
                    </li>

                    <li>
                        <strong>Respect</strong> – treating one another
                        with fairness and dignity; appreciating the unique
                        abilities and strengths of the individual.
                    </li>

                    <li>
                        <strong>Trust</strong> – building team spirit
                        and confidence by communicating openly and honestly,
                        and delivering our promises.
                    </li>

                    <li>
                        <strong>Responsibility</strong> – taking the
                        initiative to speak up and report concerns regarding
                        ethical conduct.
                    </li>

                    <li>
                        <strong>Citizenship &amp; Patriotism</strong> –
                        obeying the laws of the land and making our
                        communities better.
                    </li>

                </ul>

                <div class="quote">

                    "Great values are built on strong moral foundations.
                    Men become great when they allow values to take root
                    within their souls and live by them."

                    <div class="quote-author">
                        — Lincoln Patz
                    </div>

                </div>

            </article>

        </section>

    </main>


    <!-- ================= FOOTER ================= -->

    <footer class="site-footer">

        <div class="footer-grid">

            <!-- COMPANY INFORMATION -->

            <div class="footer-brand">

                <a href="index.php" class="logo">

                    <span class="logo-icon">
                        <i class="fa-solid fa-cubes"></i>
                    </span>

                    <span class="logo-text">
                        MSPANAY
                        <small>Distribution Inc.</small>
                    </span>

                </a>

                <p>
                    Reliable distribution and picklist monitoring services
                    across Western Visayas — built on honesty, integrity,
                    and trust.
                </p>

            </div>


            <!-- QUICK LINKS -->

            <div class="footer-col">

                <h3>Quick Links</h3>

                <ul>

                    <li>
                        <a href="index.php">Home</a>
                    </li>

                    <li>
                        <a href="#values" data-toggle-values>About Us</a>
                    </li>

                    <li>
                        <a href="#" class="js-open-login">Login</a>
                    </li>

                    <li>
                        <a href="dashboard.php">Dashboard</a>
                    </li>

                </ul>

            </div>


            <!-- COMPANY VALUES -->

            <div class="footer-col">

                <h3>Our Values</h3>

                <ul>

                    <li>
                        <a href="#values" data-toggle-values>
                            Vision &amp; Purpose
                        </a>
                    </li>

                    <li>
                        <a href="#values" data-toggle-values>
                            Work Values
                        </a>
                    </li>

                    <li>
                        <a href="#values" data-toggle-values>
                            Core Principles
                        </a>
                    </li>

                </ul>

            </div>

        </div>


        <!-- COPYRIGHT -->

        <div class="footer-bottom">

            <p>
                &copy; <?php echo date('Y'); ?>
                MSPANAY Distribution Inc.
                All rights reserved.
            </p>

        </div>

    </footer>


    <!-- ================= LOGIN POPUP ================= -->

    <div class="login-popup"
        id="loginPopup"
        role="dialog"
        aria-modal="false"
        aria-label="Login form">

        <div class="popup-head">

            <div class="p-icon">
                <i class="fa-solid fa-lock"></i>
            </div>

            <div>

                <h2>Login</h2>

                <p>Picklist Monitoring System</p>

            </div>

            <button type="button"
                class="popup-close"
                id="closeLogin"
                aria-label="Close login form">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <!-- LOGIN ERROR -->

        <?php if ($error): ?>

            <div class="popup-error">

                <i class="fa-solid fa-circle-exclamation"></i>

                <?php echo htmlspecialchars($error); ?>

            </div>

        <?php endif; ?>


        <!-- LOGIN FORM -->

        <form method="POST" action="index.php">

            <div class="popup-input">

                <i class="fa-solid fa-user"></i>

                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Username"
                    autocomplete="username"
                    required
                >

            </div>


            <div class="popup-input">

                <i class="fa-solid fa-key"></i>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Password"
                    autocomplete="current-password"
                    required
                >

            </div>


            <button type="submit" class="popup-submit">

                <i class="fa-solid fa-right-to-bracket"></i>

                Login

            </button>

        </form>


        <div class="popup-foot">

            <i class="fa-solid fa-shield-halved"></i>

            Authorized personnel only

        </div>

    </div>


    <!-- ================= BACK TO TOP ================= -->

    <button id="toTop" aria-label="Back to top">

        <i class="fa-solid fa-chevron-up"></i>

    </button>


    <!-- ================= JAVASCRIPT ================= -->

    <script>

        /* ================= LOGIN POPUP ================= */

        const loginToggle = document.getElementById('loginToggle');

        const loginPopup = document.getElementById('loginPopup');

        const closeLogin = document.getElementById('closeLogin');


        function openPopup() {

            loginPopup.classList.add('open');

            loginToggle.setAttribute('aria-expanded', 'true');

            setTimeout(() => {

                document.getElementById('username').focus();

            }, 250);

        }


        function hidePopup() {

            loginPopup.classList.remove('open');

            loginToggle.setAttribute('aria-expanded', 'false');

        }


        // Header login button

        loginToggle.addEventListener('click', (e) => {

            e.stopPropagation();

            loginPopup.classList.contains('open')
                ? hidePopup()
                : openPopup();

        });


        // Footer login link

        document.querySelectorAll('.js-open-login').forEach(el => {

            el.addEventListener('click', (e) => {

                e.preventDefault();

                openPopup();

            });

        });


        // Close button

        closeLogin.addEventListener('click', hidePopup);


        // Click outside popup

        document.addEventListener('click', (e) => {

            if (
                loginPopup.classList.contains('open') &&
                !loginPopup.contains(e.target) &&
                e.target !== loginToggle &&
                !loginToggle.contains(e.target)
            ) {

                hidePopup();

            }

        });


        // Escape key

        document.addEventListener('keydown', (e) => {

            if (e.key === 'Escape') {

                hidePopup();

            }

        });


        // Automatically open popup after incorrect login

        <?php if ($error): ?>

            openPopup();

        <?php endif; ?>


        /* ================= LEARN MORE / SHOW LESS ================= */

        const learnBtn = document.getElementById('learnBtn');

        const valuesSection = document.getElementById('values');


        function setValuesOpen(open, scrollToView) {

            if (open) {

                valuesSection.classList.remove('hidden');

                learnBtn.innerHTML =
                    'Show Less <i class="fa-solid fa-arrow-up"></i>';

                learnBtn.setAttribute('aria-expanded', 'true');

                valuesSection.style.animation = 'none';

                void valuesSection.offsetWidth;

                valuesSection.style.animation = '';

                if (scrollToView) {

                    valuesSection.scrollIntoView({

                        behavior: 'smooth',

                        block: 'start'

                    });

                }

            } else {

                valuesSection.classList.add('hidden');

                learnBtn.innerHTML =
                    'Learn More <i class="fa-solid fa-arrow-down"></i>';

                learnBtn.setAttribute('aria-expanded', 'false');

                window.scrollTo({

                    top: 0,

                    behavior: 'smooth'

                });

            }

        }


        learnBtn.addEventListener('click', () => {

            setValuesOpen(

                valuesSection.classList.contains('hidden'),

                true

            );

        });


        // About Us navigation links

        document.querySelectorAll('a[data-toggle-values]').forEach(link => {

            link.addEventListener('click', (e) => {

                e.preventDefault();

                setValuesOpen(true, true);

            });

        });


        /* ================= HEADER SCROLL EFFECT ================= */

        const header = document.querySelector('.site-header');

        window.addEventListener('scroll', () => {

            header.classList.toggle(

                'scrolled',

                window.scrollY > 10

            );

        }, { passive: true });


        /* ================= BACK TO TOP ================= */

        const toTop = document.getElementById('toTop');

        window.addEventListener('scroll', () => {

            toTop.classList.toggle(

                'visible',

                window.scrollY > 500

            );

        }, { passive: true });


        toTop.addEventListener('click', () => {

            window.scrollTo({

                top: 0,

                behavior: 'smooth'

            });

        });

    </script>

</body>
</html>
