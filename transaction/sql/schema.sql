CREATE DATABASE IF NOT EXISTS budget_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE budget_tracker;

CREATE TABLE categories (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            name VARCHAR(100) NOT NULL,
                            type ENUM('income', 'expense') NOT NULL
);

CREATE TABLE transactions (
                              id INT AUTO_INCREMENT PRIMARY KEY,
                              category_id INT NULL,
                              title VARCHAR(255) NOT NULL,
                              amount DECIMAL(10, 2) NOT NULL,
                              type ENUM('income', 'expense') NOT NULL,
                              txn_date DATE NOT NULL,
                              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                              FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS monthly_budgets (
                                               id INT AUTO_INCREMENT PRIMARY KEY,
                                               category_id INT NOT NULL,
                                               budget_month CHAR(7) NOT NULL,
    limit_amount DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_category_month (category_id, budget_month),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    );