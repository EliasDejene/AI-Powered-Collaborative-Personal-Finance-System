# 💰 AI Powered Collaborative Personal Finance & Budgeting System

> 
> Built with PHP · MySQL · JavaScript · Bootstrap 5 · Google Gemini AI

---

## 📋 Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Project Structure](#project-structure)
- [Requirements](#requirements)
- [Installation](#installation)
- [Database Setup](#database-setup)
- [Configuration](#configuration)
- [Usage Guide](#usage-guide)
- [API Endpoints](#api-endpoints)
- [AI Integration](#ai-integration)
- [Known Issues & Troubleshooting](#known-issues--troubleshooting)
- [Team](#team)

---

## Overview

An AI-powered web application that helps individuals and groups manage income, expenses, budgets, and savings using Google Gemini AI for automatic expense categorization and intelligent budget recommendations. Supports collaborative budgeting for families, couples, and small teams.

---

## Features

| Feature | Description |
|---|---|
| 🔐 Authentication | Secure login/signup with bcrypt password hashing and PHP sessions |
| 💸 Transactions | Full CRUD for income and expense records with filters |
| 🤖 AI Categorization | Auto-categorizes expenses from description using Gemini AI |
| 📊 AI Budget | Generates personalized monthly budgets from 3-month spending history |
| 👥 Group Budgeting | Collaborative groups with admin / contributor / viewer roles |
| 📈 Reports | Monthly summaries, category breakdowns, and CSV export |
| 📉 Analytics | Trend charts, yearly comparisons, and spending visualizations |
| 💡 Recommendations | AI-powered personalized financial tips |
| ⚙️ Account Settings | Profile updates, password change, account deletion |

---

## Project Structure

```
finance-system/
│
├── 📄 index.html               # Dashboard — charts, KPIs, AI insights
├── 📄 login.html               # Login page
├── 📄 signup.html              # Registration page
├── 📄 transactions.html        # Income & expense management
├── 📄 ai-budget.html           # AI budget generation & application
├── 📄 recommendations.html     # Personalized financial tips
├── 📄 reports.html             # Monthly reports & category breakdown
├── 📄 analytics.html           # Visual analytics & trend charts
├── 📄 account-settings.html    # Profile & security settings
├── 📄 about.html               # About the system
│
├── 🎨 shared-sidebar.css       # Shared responsive sidebar styles
├── 🖼️  logo.png                # App logo
│
└── backend/                    # ← ALL PHP FILES GO HERE
    ├── 📄 config.php           # DB connection, Gemini API, shared helpers
    ├── 📄 auth.php             # Login, signup, logout, session check
    ├── 📄 transactions.php     # Transaction CRUD (GET/POST/PUT/DELETE)
    ├── 📄 reports.php          # Dashboard, monthly, category, yearly reports
    ├── 📄 ai-budget.php        # AI budget generation and storage
    ├── 📄 ai-categorize.php    # AI expense categorization
    ├── 📄 account.php          # Profile management
    └── 📄 groups.php           # Collaborative group management
```

> ⚠️ **Important:** All `.php` files must be placed inside a `backend/` subfolder. The HTML files call `backend/auth.php`, `backend/transactions.php`, etc.

---

## Requirements

### Software
| Software | Version | Download |
|---|---|---|
| XAMPP (Apache + MySQL + PHP) | 8.2.x | [apachefriends.org](https://www.apachefriends.org) |
| PHP | 8.x | Included with XAMPP |
| MySQL | 8.x | Included with XAMPP |
| Any modern browser | Latest | Chrome recommended |

### Internet
- Required for AI features (Gemini API calls)
- All other features work fully offline

---

## Installation

### Step 1 — Install XAMPP
Download and install XAMPP. Make sure **Apache** and **MySQL** are selected during installation.

### Step 2 — Copy the Project
Place the entire project folder inside XAMPP's web root:

```
Windows:  C:\xampp\htdocs\finance-system\
Linux:    /opt/lampp/htdocs/finance-system/
Mac:      /Applications/XAMPP/htdocs/finance-system/
```

Your folder structure should look like:
```
htdocs/
└── finance-system/
    ├── index.html
    ├── login.html
    ├── shared-sidebar.css
    ├── logo.png
    └── backend/
        ├── config.php
        ├── auth.php
        └── ... (all other .php files)
```

### Step 3 — Start XAMPP
Open the XAMPP Control Panel and click **Start** for both:
- ✅ Apache
- ✅ MySQL

### Step 4 — Create the Database
Open your browser and go to: `http://localhost/phpmyadmin`

1. Click **New** in the left sidebar
2. Enter database name: `finance_db`
3. Click **Create**

### Step 5 — Run the SQL Script
In phpMyAdmin, select `finance_db`, click the **SQL** tab, paste and run:

```sql
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  type ENUM('income','expense') NOT NULL,
  category VARCHAR(80) DEFAULT 'Other',
  ai_suggested TINYINT(1) DEFAULT 0,
  transaction_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE budgets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  category VARCHAR(80) NOT NULL,
  recommended_amount DECIMAL(12,2) DEFAULT 0,
  actual_spent DECIMAL(12,2) DEFAULT 0,
  month TINYINT NOT NULL,
  year SMALLINT NOT NULL,
  ai_generated TINYINT(1) DEFAULT 0,
  UNIQUE KEY uq_budget (user_id, category, month, year),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE `groups` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT,
  created_by INT,
  total_budget DECIMAL(12,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE group_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('admin','contributor','viewer') DEFAULT 'contributor',
  FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE group_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  added_by INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category VARCHAR(80) DEFAULT 'Other',
  transaction_date DATE NOT NULL,
  FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE ai_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(50),
  input_text TEXT,
  ai_response TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Step 6 — Configure the App
Open `backend/config.php` and update:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'finance_db');
define('DB_USER', 'root');        // ← your MySQL username
define('DB_PASS', '');            // ← your MySQL password (blank by default in XAMPP)
define('GEMINI_API_KEY', 'YOUR_API_KEY_HERE'); // ← get free key below
```

### Step 7 — Open the App
Navigate to: **`http://localhost/finance/login.html`**

Click **Create Account** to register and start using the system.

---

## Configuration

### Getting a Free Gemini API Key
1. Go to [https://aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)
2. Sign in with a Google account
3. Click **Create API Key**
4. Copy the key and paste it into `backend/config.php`

**Free tier limits (gemini-1.5-flash):**
- 15 requests/minute
- 1,500 requests/day

> If you exceed the limit or have no internet, the system automatically falls back to keyword-based categorization and percentage-based budget templates — so it still works.

---

## Usage Guide

### Adding a Transaction
1. Go to **Transactions** in the sidebar
2. Click **Add Transaction**
3. Enter description, amount, type (income/expense), and date
4. Click **Suggest Category** — AI will auto-fill the category
5. Accept or change the category, then click **Save**

### Generating an AI Budget
1. Go to **AI Budget** in the sidebar
2. Click **Generate AI Budget**
3. Review the category-by-category recommendations
4. Click **Apply Budget** to save them

### Collaborative Groups
1. Navigate to the Groups section
2. Click **Create Group**, enter a name and budget
3. As admin, invite members by their registered email
4. Assign roles: `admin` (full control) · `contributor` (can add transactions) · `viewer` (read only)

---

## API Endpoints

All endpoints are in the `backend/` folder and return JSON.

### Auth — `auth.php`
| Method | Action | Description |
|---|---|---|
| POST | `?action=signup` | Register new user |
| POST | `?action=login` | Login and create session |
| POST | `?action=logout` | Destroy session |
| GET | `?action=me` | Get current user info |

### Transactions — `transactions.php`
| Method | URL | Description |
|---|---|---|
| GET | `transactions.php` | List all transactions (supports filters) |
| POST | `transactions.php` | Create new transaction |
| PUT | `transactions.php?id=N` | Update transaction |
| DELETE | `transactions.php?id=N` | Delete transaction |

**GET filters:** `?type=expense&category=Food&month=4&year=2025`

### Reports — `reports.php`
| Action | Description |
|---|---|
| `?action=dashboard` | Current month summary + AI insight |
| `?action=monthly` | Last 6 months table data |
| `?action=category` | Category breakdown for current month |
| `?action=yearly` | Monthly income vs expense for a year |

### AI Budget — `ai-budget.php`
| Method | Action | Description |
|---|---|---|
| POST | `?action=generate` | Generate AI budget from spending history |
| POST | `?action=apply` | Save generated budgets to DB |
| GET | `?action=current` | Get this month's saved budget |

### AI Categorize — `ai-categorize.php`
| Method | Body | Returns |
|---|---|---|
| POST | `{ "description": "Lunch at Kaldi's" }` | `{ "category": "Food & Dining", "confidence": "high" }` |

### Groups — `groups.php`
| Method | URL | Description |
|---|---|---|
| GET | `groups.php` | List user's groups |
| POST | `groups.php` | Create group |
| GET | `groups.php?id=N` | Get group details + members |
| POST | `groups.php?id=N&action=invite` | Invite member by email |
| POST | `groups.php?id=N&action=tx` | Add group transaction |
| GET | `groups.php?id=N&action=tx` | List group transactions |

---

## AI Integration

The AI layer uses **Google Gemini 1.5 Flash** via HTTPS REST calls handled by `callAI()` in `config.php`.

### Expense Categorization Flow
```
User types description
      ↓
ai-categorize.php sends prompt to Gemini
      ↓
Gemini returns one of:
  Food & Dining | Transportation | Utilities |
  Entertainment | Health | Housing | Education |
  Savings | Income | Other
      ↓
Category shown to user (they can override)
      ↓
Transaction saved with ai_suggested = 1
```

### Budget Recommendation Flow
```
ai-budget.php fetches last 3 months of expenses from DB
      ↓
Sends spending summary + monthly income to Gemini
      ↓
Gemini returns JSON array:
  [{ category, recommended_amount, previous_avg, status, tip }]
      ↓
Displayed as budget cards in ai-budget.html
      ↓
User clicks Apply → saved to budgets table
```

### Fallback Behavior
| Scenario | Categorization | Budget |
|---|---|---|
| AI available | Gemini NLP | Gemini JSON budget |
| No internet / API error | Keyword matching (regex) | % of income defaults |

---

## Known Issues & Troubleshooting

### ❌ "Failed to Fetch" on Login
**Cause:** PHP files are not in the `backend/` folder, or Apache is not running.  
**Fix:**
1. Make sure XAMPP Apache is started (green in Control Panel)
2. Confirm all `.php` files are in `htdocs/finance-system/backend/`
3. Open the app via `http://localhost/...` not by double-clicking the HTML file

### ❌ "Unauthorized. Please login." on every page
**Cause:** PHP sessions are not persisting between requests.  
**Fix:** Ensure you're accessing the site through `http://localhost/` and not a file path. Sessions don't work on `file://` URLs.

### ❌ AI features return errors
**Cause:** Invalid or missing Gemini API key, or no internet connection.  
**Fix:**
1. Check `GEMINI_API_KEY` in `backend/config.php`
2. Get a new key at [aistudio.google.com](https://aistudio.google.com/app/apikey)
3. The system will use fallback logic automatically — other features are unaffected

### ❌ Logo not showing
**Cause:** The HTML pages look for `img/logo.png` but the logo is in the root folder.  
**Fix:** Create an `img/` subfolder inside your project and put `logo.png` inside it.

---

