# CROSSUNDER
Web application for online footwear store

====================================================================
  CROSSUNDER™ ONLINE SYSTEM — SETUP GUIDE
  CSC264 | Introduction to Web and Mobile Application
  Group CS1104D | FSKM, UiTM Kampus Raub
====================================================================

── REQUIREMENTS ────────────────────────────────────────────────────
  • XAMPP (PHP 8.x + Apache + MySQL/MariaDB)
  • phpMyAdmin
  • A modern browser (Chrome, Edge, Firefox)

── INSTALLATION STEPS ──────────────────────────────────────────────

  STEP 1 — Copy project folder
    Copy the entire `crossunder/` folder into:
    C:\xampp\htdocs\crossunder\

  STEP 2 — Create the database
    a. Open phpMyAdmin (http://localhost/phpmyadmin)
    b. Create a new database named:  footweardb
    c. Import the file:              footweardb.sql

  STEP 3 — Create the Admin account
    a. In phpMyAdmin, click on `footweardb` → SQL tab
    b. Copy and run the contents of:  setup_admin.sql
       (This inserts the admin login record)

  STEP 4 — Check config
    Open:  dbconn.php
    Confirm these match your XAMPP settings:
      DB_HOST = 'localhost'
      DB_NAME = 'footweardb'
      DB_USER = 'root'
      DB_PASS = ''          ← blank by default in XAMPP

  STEP 5 — Add product images (optional)
    Create a folder:  crossunder/images/
    Place shoe images named to match IMAGE_URL values in the DB.
    Missing images will auto-display a placeholder.

  STEP 6 — Launch the app
    Open:  http://localhost/crossunder/

── DEMO LOGIN ACCOUNTS ──────────────────────────────────────────────

  ROLE      USERNAME      PASSWORD
  ────────  ────────────  ──────────
  ADMIN     admin         admin123
  STAFF     johnstaff     passjohn
  STAFF     marystaff     passmary
  CUSTOMER  ali123        passali
  CUSTOMER  siti123       passsiti

── FILE STRUCTURE ───────────────────────────────────────────────────

  crossunder/
  ├── config/
  │   └── dbconn.php                ← PDO database connection
  ├── images/                   ← Place product images here
  ├── index.php                 ← Entry point (auto-redirects)
  ├── login.php                 ← Unified login (all roles)
  ├── logout.php                ← Session destroy
  ├── register.php              ← Customer self-registration
  ├── customer_dashboard.php    ← Product catalog + search
  ├── cart.php                  ← Cart management
  ├── checkout.php              ← Checkout + stock deduction
  ├── receipt.php               ← Digital receipt (printable)
  ├── staff_dashboard.php       ← Order processing + stock view
  ├── admin_dashboard.php       ← Full admin CRUD panel
  ├── setup_admin.sql           ← Run ONCE to create admin account
  └── README.md                ← This file

── FEATURES IMPLEMENTED ─────────────────────────────────────────────

  ✅ Role-based login (CUSTOMER / STAFF / ADMIN)
  ✅ SweetAlert2 popup on wrong username or password
  ✅ Customer registration (INSERT into customer + login tables)
  ✅ Password hashing with password_hash() for new accounts
  ✅ Legacy plain-text fallback for demo seed data
  ✅ Customer: Browse catalog with search (name, brand, price range)
  ✅ Customer: Dynamic min/max price filter from live DB values
  ✅ Customer: Add to cart, update qty, remove item, clear cart
  ✅ Customer: Checkout with delivery address + payment method
  ✅ Checkout: PDO beginTransaction() / commit() / rollback()
  ✅ Checkout: Stock verification BEFORE deduction
  ✅ Checkout: Automatic QTY_AVAILABLE deduction on purchase
  ✅ Checkout: Cart cleared after successful transaction
  ✅ Digital receipt — printable HTML page
  ✅ Staff: View all orders, update payment status
  ✅ Staff: Stock overview with low-stock highlighting
  ✅ Admin: Dashboard with live sales stats
  ✅ Admin: Product CRUD (Add / Edit / Delete + stock)
  ✅ Admin: Staff CRUD (Add / Edit / Delete + login account)
  ✅ Admin: Customer records with search by name or ID
  ✅ Admin: Full order/transaction history
  ✅ Admin: Register staff login (username + hashed password)
  ✅ Session protection on all pages
  ✅ Bootstrap 5 responsive design throughout
  ✅ SQL injection prevention via PDO prepared statements

