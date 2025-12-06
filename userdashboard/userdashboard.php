<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Real Estate Landing Page</title>
    <style>
        /* General Styles */
        body {
            font-family: Arial, sans-serif;
            font-size: 18px;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            background-color: #f8f8f8;
            text-align: center;
        }

        /* Navigation Bar */
        nav {
            background: #2d4e1e;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 70px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
        }

        .nav-left, .nav-right {
            list-style: none;
            display: flex;
            align-items: center; /* Ensure items are vertically centered */
            margin: 0;
            padding: 0;
        }

        .nav-left li, .nav-right li {
            margin-right: 40px;
            display: flex;
            align-items: center; /* Ensure items are vertically centered */
        }

        .nav-left li a, .nav-right li a {
            font-size: 18px;
            color: white;
            text-decoration: none;
            font-weight: bold;
            padding: 10px 15px;
            transition: transform 0.2s ease, color 0.2s ease;
        }

        .nav-left li a:hover, .nav-right li a:hover {
            transform: translateY(-3px);
            color: #f4d03f;
        }

        .nav-logo {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            z-index: 2;
            top: 50%;
            transform: translate(-50%, -50%);
        }

        .nav-logo img {
            width: 80px;
            height: auto;
        }


        /* Header */
        header {
            background: url('../assets/g.jpg') center/cover no-repeat;
            text-align: center;
            padding: 100px 20px;
            color: white;
            position: relative;
        }

        header h1 {
            font-size: 42px;
            text-shadow: 2px 2px 8px rgba(0, 0, 0, 0.5);
        }

        header input {
            padding: 14px;
            font-size: 18px;
            width: 80%;
            max-width: 450px;
            border: none;
            border-radius: 5px;
            outline: none;
        }

        /* Features Section */
        .features {
            text-align: center;
            padding: 60px 20px;
        }

        .features h2 {
            color: #2d4e1e;
            font-size: 32px;
            margin-bottom: 20px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            max-width: 1000px;
            margin: auto;
            text-align: left;
            font-size: 20px;
        }

        .buttons a {
            display: inline-block;
            font-size: 20px;
            margin: 15px;
            padding: 14px 28px;
            background: #2d4e1e;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: transform 0.2s ease, background 0.3s ease;
            font-weight: bold;
        }

        .buttons a:hover {
            transform: translateY(-3px);
            background: #3a6c28;
        }

        /* Properties Section */
        .properties {
            text-align: center;
            padding: 40px 20px;
            background-color: #f5f5f5;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .properties-title {
            font-size: 32px;
            color: #2d4e1e;
            margin-bottom: 20px;
            width: 100%;
        }

        .property-card {
            width: 300px;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out;
        }

        .property-card:hover {
            transform: scale(1.05);
        }

        .property-card img {
            width: 100%;
            max-width: 250px;
            border-radius: 10px;
            display: block;
            margin: 0 auto;
        }

        .property-card .price {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
        }

        .property-card button {
            background: white;
            color: #2d4e1e;
            font-size: 14px;
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
        }

        .property-card button:hover {
            background: #f4d03f;
        }

        /* Profile Dropdown */
        #profileMenu {
            display: none;
            position: absolute;
            top: 50px;
            right: 0;
            background: white;
            list-style: none;
            padding: 10px 0;
            margin: 0;
            border-radius: 8px;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
            width: 300px;
        }

        #profileMenu li a {
            display: block;
            padding: 10px 20px;
            color: #2d4e1e;
            text-decoration: none;
        }

        #profileMenu li a:hover {
            color: #f4d03f;
            border-radius: 5px;
        }

        /* Footer */
        footer {
            display: flex;
            align-items: center;
            background: #2d4e1e;
            color: white;
            padding: 30px 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        footer img {
            width: 120px;
            border-radius: 50%;
            margin-right: 20px;
        }

        footer .text {
            max-width: 650px;
            text-align: left;
            font-size: 20px;
            line-height: 1.8;
        }

        /* Social Media Section */
        .social-media {
            background: white;
            padding: 40px 20px;
        }

        .social-media h2 {
            font-size: 22px;
            margin-bottom: 10px;
        }

        .social-media p {
            font-size: 16px;
            max-width: 600px;
            margin: auto;
            line-height: 1.5;
        }

        .icons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }

        .icons a img {
            width: 40px;
            height: 40px;
            transition: transform 0.2s ease;
        }

        .icons a img:hover {
            transform: scale(1.1);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            nav {
                flex-direction: column;
                text-align: center;
            }

            .nav-logo {
                position: static;
                transform: none;
                margin-top: 10px;
            }

            .nav-right {
                flex-direction: column;
                padding-top: 10px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            footer {
                flex-direction: column;
                text-align: center;
            }

            footer img {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <nav>
        <ul class="nav-left">
            <li><a href="../userlot.php">Buy</a></li>
            <li><a href="../findagent.php">Find Agent</a></li>
            <li><a href="../about.html">About</a></li>
        </ul>
    
        <div class="nav-logo">
            <a href="../userdashboard.php">
                <img src="../assets/f.png" alt="Centered Logo">
            </a>
        </div>
    
        <ul class="nav-right">
            <li><a href="../faqs.html">FAQs</a></li>
            <li><a href="../contact.html">Contact</a></li>
            <!-- Profile Dropdown -->
            <li style="position: relative;">
                <img src="../assets/s.png" alt="Profile" id="profileBtn" style="width: 40px; height: 40px; border-radius: 50%; cursor: pointer;">
                <ul id="profileMenu">
                    <li><a href="usersettings.php">Account Settings</a></li>
                    <li><a href="logout.php">Lot Progress Tracker</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </li>
        </ul>
    </nav>
    
    <header style="text-align: left;">
        <h1>Discover House and Lot for Sale</h1>
        <br>
        <input type="text" placeholder="Enter a Barangay or Lot Number">
    </header>
    
    <section class="features">
        <h2>El Nuevo Puerta Real Estate Offers</h2>
        <br>
        <div class="features-grid">
            <div>✔ Scenic Views</div>
            <div>✔ Investment Opportunities</div>
            <div>✔ Prime Lots in Ideal Locations</div>
            <div>✔ Ready-to-Build Lots</div>
            <div>✔ Prime Accessibility</div>
            <div>✔ Unmatched Value</div>
            <div>✔ Customizable Lot Sizes</div>
            <div>✔ Eco-Friendly Options</div>
            <div>✔ Hassle-Free Transactions</div>
        </div>
        <br>
        <br>
        <div class="buttons">
            <a href="../userlot.php">Explore Lots</a>
            <a href="../findagent.php">Contact Agent</a>
        </div>
    </section>

    <br>

    <footer>
        <img src="../assets/d.jpg" alt="Founder Image">
        <div class="text">
            <h3>Welcome to Nuevo Puerta Real Estate!</h3>
            <p>Our mission is simple: <br> To provide you with premium lots that give you the freedom to create your future. Whether you're planning your dream home, starting a new business, or making a smart investment, we're here to help you every step of the way.</p>
            <br>
            <p><strong>Rosibelle G. Ituralde</strong><br>CEO / FOUNDER</p>
        </div>
    </footer>

    <section class="social-media">
        <h2>Stay Connected with Nuevo Puerta!</h2>
        <p>Follow us on social media for the latest updates, exclusive offers, and inspiration for your next big project. Don’t miss out on exciting opportunities and tips to help you build your dreams!</p>
        
        <div class="icons">
            <a href="https://www.facebook.com/nuevopuertarealestate" target="_blank">
                <img src="../assets/fb.png" alt="Facebook">
              </a>              
              <a href="mailto:nuevopuertarealestate@gmail.com">
                <img src="../assets/email.png" alt="Email">
              </a>              
        </div>
    </section>

    <script>
    const profileBtn = document.getElementById('profileBtn');
    const profileMenu = document.getElementById('profileMenu');

    profileBtn.addEventListener('click', () => {
        profileMenu.style.display = (profileMenu.style.display === 'block') ? 'none' : 'block';
    });

    // Optional: Close the menu if clicked outside
    document.addEventListener('click', function(event) {
        if (!profileBtn.contains(event.target) && !profileMenu.contains(event.target)) {
            profileMenu.style.display = 'none';
        }
    });
</script>
</body>
</html>
