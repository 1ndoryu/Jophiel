# Jophiel - Intelligent Recommendation Engine 🎶

[](https://php.net/)
[](https://www.google.com/search?q=LICENSE)
[](https://www.workerman.net/webman)

An intelligent, event-driven recommendation and content personalization system designed for an audio sample platform.

## 🎯 Vision

Jophiel's mission is to **connect artists with the sounds they need**. It achieves this by presenting each user with a unique, algorithmically curated feed based on their tastes, interactions, and social connections, ensuring a tailored and inspiring discovery experience.

-----

## ✨ Core Features

  * **🎧 Personalized User Feed:** The main feed is unique for every user, pre-calculated based on a comprehensive scoring model.
  * **👯 "Similar Samples":** On each sample's page, Jophiel can provide on-demand recommendations for similar-sounding content.
  * **💡 "Ideas for Boards":** Suggests new content that matches the overall "essence" of a user's existing collection or board.

-----

## 🏗️ Architecture Overview

Jophiel operates as a standalone service that integrates with the main platform CMS (`sword`). Its architecture is designed for maximum efficiency and scalability using a hybrid processing model.

  * **🧠 Deep Analysis (Batch Process):** A background worker that periodically runs to:

    1.  Process all new user interactions.
    2.  Update user taste profiles (vectors).
    3.  Pre-calculate and store the final recommendation lists for every user.

  * **⚡ Immediate Reaction (Event-Driven):** A lightweight, real-time system that activates on high-value user interactions (like a `like` or `follow`). It doesn't recalculate everything but intelligently injects or re-orders relevant content in the user's feed, providing a sense of instant responsiveness.

### Data Flow

The system is fully decoupled, with data flowing between services via **RabbitMQ**.

`Sword (CMS)` ➡️ `Casiel (Audio Analysis)` ➡️ `Sword (Update)` ➡️ `Jophiel (Vectorize & Recommend)`

-----

## 🛠️ Technology Stack

  * **Backend:** **PHP 8.1+** on **[Webman Framework](https://www.workerman.net/webman)** (High-performance, based on Workerman)
  * **Database:** **PostgreSQL** (leveraging JSONB and GIN indexes for performance)
  * **Message Queue:** **RabbitMQ** (for decoupled, event-driven communication)
  * **Core Libraries:**
      * `illuminate/database`: Eloquent ORM for database interaction.
      * `monolog/monolog`: For structured, channel-based logging.
      * `php-amqplib/php-amqplib`: For RabbitMQ integration.

-----

## 🚀 Getting Started

Follow these steps to get a local instance of Jophiel up and running.

### Prerequisites

  * PHP \>= 8.1
  * Composer
  * PostgreSQL Server
  * RabbitMQ Server

### Installation

1.  **Clone the repository:**

    ```bash
    git clone https://github.com/1ndoryu/Jophiel
    cd Jophiel
    ```

2.  **Set up your environment:**

      * Create an `.env` file from the example or your existing one.
      * Update the `.env` file with your **PostgreSQL** and **RabbitMQ** connection details.

    <!-- end list -->

    ```env
    # Application
    APP_NAME="Jophiel"
    APP_ENV=development
    APP_DEBUG=true

    # Database Configuration (PostgreSQL)
    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=jophiel
    DB_USERNAME=your_db_user
    DB_PASSWORD=your_db_password

    # RabbitMQ Connection (for consuming events from Sword)
    RABBITMQ_HOST=127.0.0.1
    RABBITMQ_PORT=5672
    RABBITMQ_USER=guest
    RABBITMQ_PASS=guest
    ```

3.  **Install PHP dependencies:**

    ```bash
    composer install
    ```

4.  **Set up the database schema:**
    This command will create all the necessary tables, types, and indexes in your PostgreSQL database.

    ```bash
    php jophiel db:install
    ```

### Running the Application

1.  **Start the server and background processes:**
    This single command starts the HTTP server for the API and the background workers for the Batch Process and Event Consumer.

    ```bash
    php start.php start
    ```

    For development, you can run it in daemon mode:

    ```bash
    php start.php start -d
    ```

2.  **(Optional) Seed the database with test data:**
    To test the system, you can populate the database with fake users, samples, and interactions.

    ```bash
    # Seed with default values (50 users, 500 samples, 5000 interactions)
    php jophiel db:seed

    # Or specify custom amounts
    php jophiel db:seed --users=100 --samples=1000 --interactions=10000
    ```

-----

## ⚙️ CLI Commands

Jophiel includes a powerful CLI tool (`jophiel`) for managing the application.

| Command | Description | Example |
| :--- | :--- | :--- |
| `db:install` | Creates the required database tables and schema. | `php jophiel db:install` |
| `db:reset` | **Deletes all data** from Jophiel's tables. | `php jophiel db:reset` |
| `db:seed` | Populates the database with test data. | `php jophiel db:seed --users=20` |
| `batch:run` | Manually executes a single cycle of the main batch process. | `php jophiel batch:run` |
| `quick-update:test`| Simulates a 'like' event to test the immediate reaction system. | `php jophiel quick-update:test` |
| `user:recalc` | Runs a full recalculation for a specific user. | `php jophiel user:recalc --user=123` |
| `test:events`| Runs a full suite of event simulation tests. | `php jophiel test:events` |

-----

## 📡 API Endpoints

Jophiel exposes a simple API for the main application (`sword`) to consume recommendations.

  * `GET /v1/feed/{user_id}`: Retrieves the paginated list of recommended `sample_id`s for a user.
  * `GET /v1/taste/{user_id}`: Returns a human-readable summary of a user's taste profile.
  * `GET /v1/sync/...`: A set of internal endpoints for data synchronization checksums and ID lists.

-----

## 🧩 Event-Driven Integration

Jophiel is designed to be completely decoupled. It listens for events published by `sword` on a **RabbitMQ topic exchange** named `sword_events`.

  * **Listens for:** `sample.lifecycle.*` (created, updated, deleted) and `user.interaction.*` (like, follow, etc.).
  * For the complete data contract and event specifications, please see the **[EVENTS.md](https://www.google.com/search?q=EVENTS.md)** file.

-----

## 🛣️ Project Roadmap

The project is planned in two main phases to ensure a robust launch and future scalability.

  * **Phase 1 (Current):** A fully functional and robust recommendation system using **intelligent PostgreSQL pre-filtering** (GIN indexes on JSONB) to find candidate samples. This is highly efficient for datasets up to hundreds of thousands of samples.

  * **Phase 2 (Future Scale):** To dramatically optimize performance as the sample library grows into the millions, a dedicated **Approximate Nearest Neighbor (ANN) search microservice** will be developed. The current Batch Process will be updated to call this new service's API instead of querying PostgreSQL, with no other changes to Jophiel's architecture.

      * **Proposed Tech:** Python with the **Faiss** library.

-----

## 📜 License

This project is licensed under the MIT License. See the [LICENSE](https://www.google.com/search?q=LICENSE) file for details.