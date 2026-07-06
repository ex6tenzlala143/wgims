# CISys — Consumables Inventory System

**DSWD Region X — Northern Mindanao**

A comprehensive inventory management system for tracking consumables, purchase orders, requisitions, and stock cards.

---

## 🚀 Features

### User Roles
- **Administrator** — Full system access, manage all centers and users
- **Supply Custodian** — Manage center inventory, approve requisitions
- **Center Head** — View inventory, approve requisitions
- **Center Staff** — View inventory, create requisitions

### Core Modules
1. **Dashboard** — Overview of inventory balances, statistics, and quick navigation
2. **Items** — Manage inventory items with auto-generated stock numbers
3. **Purchase Orders** — Create POs, record deliveries, track status
4. **Requisitions (RIS)** — Request and issue supplies with approval workflow
5. **Stock Cards** — Track item movements (receipts, issues, balances) with FIFO support
6. **Suppliers** — Manage supplier information
7. **Reports** — RPCI, RSMI, and Inventory Balance reports with snapshot history
8. **Centers** — Manage organizational centers (Admin only)
9. **Users** — User management with role-based permissions (Admin only)

### Key Features
- ✅ Auto-generated stock numbers based on center code and category
- ✅ Automatic stock card entries for all transactions
- ✅ FIFO (First-In-First-Out) inventory tracking by unit cost
- ✅ Partial delivery and partial approval support
- ✅ Real-time notifications for POs, deliveries, and approvals
- ✅ Printable forms (RIS, Stock Cards, Reports) matching government formats
- ✅ Monthly report snapshots for historical tracking
- ✅ Role-based access control — center users see only their center's data
- ✅ Responsive, user-friendly interface with modern design

---

## 📋 Requirements

- PHP 8.2+
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- Apache/Nginx with mod_rewrite enabled

---

## 🛠️ Installation

### 1. Clone or Extract
```bash
# If using Git
git clone <repository-url> cisys
cd cisys

# Or extract the ZIP file to your web server directory
```

### 2. Install Dependencies
```bash
composer install
```

### 3. Configure Environment
```bash
# Copy the environment file
cp .env.example .env

# Edit .env and set your database credentials
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cisys
DB_USERNAME=root
DB_PASSWORD=your_password

# Generate application key
php artisan key:generate
```

### 4. Create Database
```sql
CREATE DATABASE cisys CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Run Migrations and Seed
```bash
php artisan migrate:fresh --seed
```

This will create all tables and seed the database with:
- 4 sample centers
- 1 admin user
- 3 center users (custodian, head, staff)
- 2 sample suppliers

### 6. Configure Web Server

**Apache (.htaccess already included)**
Point your document root to the `public` folder.

**Nginx**
```nginx
server {
    listen 80;
    server_name cisys.local;
    root /path/to/cisys/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 7. Set Permissions (Linux/Mac)
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

## 🔐 Default Login Credentials

### Administrator
- **Username:** `admin`
- **Password:** `Admin@1234`

### Supply Custodian
- **Username:** `custodian1`
- **Password:** `Password@1`

### Center Head
- **Username:** `centerhead1`
- **Password:** `Password@1`

### Center Staff
- **Username:** `staff1`
- **Password:** `Password@1`

**⚠️ IMPORTANT:** Change these passwords immediately after first login!

---

## 📊 Account Codes & Categories

The system uses the following account codes for inventory classification:

| Category | Account Code | Description |
|----------|--------------|-------------|
| Office Supplies | 10-502-01-01 | Office Supplies Inventory |
| Food Supplies | 10-502-01-02 | Food Supplies Inventory |
| Drugs & Medicines | 10-502-01-03 | Drugs and Medicines Inventory |
| Medical Lab | 10-502-01-04 | Medical Laboratory and Supply Inventory |
| Medical & Dental | 10-502-01-05 | Medical and Dental for Distribution |
| Other Supplies Dist | 10-502-01-06 | Other Supplies Distribution |
| Other Supply Inv | 10-502-01-07 | Other Supply Inventory |

---

## 📝 Usage Guide

### Adding Items
1. Navigate to **Items** → **Add Item**
2. Fill in description, unit, category, and center
3. Stock number is auto-generated (e.g., `RX-MO-OFF-0001`)
4. Account code is auto-filled based on category

### Creating Purchase Orders
1. Navigate to **Purchase Orders** → **New PO**
2. Enter PO details and select supplier
3. Add line items with quantities and unit costs
4. System calculates totals automatically
5. Submit to create the PO

### Recording Deliveries
1. Open a Purchase Order
2. Click **Record Delivery**
3. Enter delivery date and quantities received
4. Stock is automatically updated
5. Stock card entries are created automatically

### Creating Requisitions (RIS)
1. Navigate to **Requisitions** → **New RIS**
2. Fill in requisition details and purpose
3. Add items with requested quantities
4. System shows stock availability
5. Submit for approval

### Approving Requisitions
1. Open a pending RIS
2. Click **Approve**
3. Adjust quantities if stock is insufficient
4. Enter signatory information
5. Process approval — stock is deducted automatically

### Viewing Stock Cards
1. Navigate to **Stock Cards**
2. Select a category (Office Supplies, Food, etc.)
3. View all items with current balances
4. Click **View History** for detailed movements
5. Click **By Unit Cost** for FIFO batch tracking
6. Print official stock card format

### Generating Reports
- **RPCI Report** — Physical count of all inventories
- **RSMI Report** — Supplies and materials issued
- **Inventory Balance** — Consolidated view across all centers (Admin only)
- Save monthly snapshots for historical records

---

## 🔒 Security Features

- Password hashing with bcrypt
- CSRF protection on all forms
- SQL injection prevention via Eloquent ORM
- XSS protection via Blade templating
- Role-based access control
- Center-based data isolation
- Session management with secure cookies

---

## 🐛 Troubleshooting

### "Access denied" errors
- Check database credentials in `.env`
- Ensure MySQL user has proper permissions

### "Class not found" errors
```bash
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

### Permission errors (Linux/Mac)
```bash
sudo chmod -R 775 storage bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

### Routes not working
- Ensure mod_rewrite is enabled (Apache)
- Check that document root points to `public` folder
- Clear route cache: `php artisan route:clear`

---

## 📞 Support

For issues, questions, or feature requests, contact your system administrator.

---

## 📄 License

This system is developed for DSWD Region X — Northern Mindanao.

---

**Built with Laravel 12 & PHP 8.2**
