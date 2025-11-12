# 💳 Culture Shock Payments   test
  
## Introduction

**Culture Shock** is a platform designed to celebrate cultural diversity and foster a positive work environment. As a recognition and reward system, **Culture Shock** empowers teams to acknowledge dedication, effort, and achievements by offering meaningful rewards to employees. To power its gifting and rewards functionality, **Culture Shock** integrates with the **CULTURESHOCK-PAYMENTS** microservice — a backend service responsible for securely handling financial transactions. <br>

**CULTURESHOCK-PAYMENTS**  is a flexible Laravel-based microservice for seamless payment integration. It supports major platforms such as:

- **Google Pay**
- **Apple Pay**
- **Point-of-Sale (POS)** systems

It ensures secure, scalable payment handling across multiple projects. For example, **ECOMMERCE** uses **CULTURESHOCK-PAYMENTS**  for transactions.

---

## Features

- **Google Pay Integration**
- **Apple Pay Integration**
- **POS System Support**
- **Secure Payment Processing**  
- **Easy Integration Across Projects**  

---

## Prerequisites

Make sure the following tools are installed:

- **Node.js** & **npm**

- **PHP**

- **Composer**

- **Relational database** maybe MySQL/MariaDb

---

## Full Installation Guide

Follow these steps to get the **CULTURESHOCK-PAYMENTS** microservice up and running on your local machine:

1. **Fork the Repository.** <br>
    Fork the repository **cultureshock-payments** to your **GitHub** account. This allows you to work on the project independently.

2. **Clone to Local Machine.** <br>
    Clone (download) your forked repository **cultureshock-payments** to your computer:

    ```
    https://github.com/<your_username>/cultureshock-payments.git
    ```

    OR

    ```
   git@github.com:<your_username>/cultureshock-payments.git
   ```

3. **Navigate to Project Directory.** <br>
    Move into the project folder:

    ```
    cd cultureshock-payments
    ```

    > **Note:** You can also navigate directly into the cloned project folder using your file explorer.

4. **Install Node.js Dependencies.** <br>
    Install all front-end dependencies using npm:

    ```
    npm install
    ```

5. **Install Composer Dependencies.** <br>
    Install the backend dependencies using Composer:

    ```
    composer install
    ```

6. **Create Database.**<br>
    Set up a new database for the project using your MySQL or MariaDB server (or any other supported relational database).

7. **Configure Environment File.** <br>
    Copy the example environment file **.env.example** and rename it to **.env**. This file is where you'll configure your database credentials and other settings.

    ```
    copy .env.example .env
    ```

    > **Note:** You can manually rename the **.env.example** file to **.env** file.

    Open the **.env** file and update the following lines with your database details:

    ```
    DB_DATABASE= name_of_database_you_created
    DB_USERNAME=username_of_database_you_created
    DB_PASSWORD=password_of_database_you_created
    ```

    >**Note**: If you're having trouble with your database, it might be set to use **SQLite** or any other database instead of MySQL. Just go to your **.env** file and change **DB_CONNECTION=sqlite** to **DB_CONNECTION=mysql.**

8. **Run Database Migrations.** <br>
    Set up your database tables using Laravel's migration system:

    ```
    php artisan migrate
    ```

9. **Generate Application Key.** <br>
    Generate a unique application key required by Laravel:

    ```
    php artisan key:generate
    ```

10. **Generate JWT Secret.** <br>
    This microservice uses JSON Web Tokens (JWT) for authentication, generate the JWT secret:

    ```
    php artisan jwt:secret
    ```

11. **Running the project.** <br>
    Start the Laravel development server:

    ```
    php artisan serve
    ```

    Your project will be available at: <http://127.0.0.1:8000>. For a cleaner and more memorable local URL **(e.g., cultureshock-payments.test)**, you can set up a virtual host.

---

## Working on a Feature

To keep the codebase clean and maintainable, follow this basic workflow when adding a new feature:<br>

**Workflow**

- Main Branch (main) <br>
 Contains only production-ready code. Do not commit directly to this branch.

- Feature Branch <br>
 Create a new branch from main for each feature or change:

- After completing your work, push the branch and open a **Pull Request** to **main**. Include a brief, clear summary of the changes made, and add the task ID to the comments.

> Ensure your **.env** file is properly configured. Never commit **.env**  files or any sensitive credentials.
---

## Need Help?

The internal development team can help you if you have any questions.
