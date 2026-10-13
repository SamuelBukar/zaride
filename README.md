# ZaRide — Peer-to-Peer (P2P) Mobility & Logistics Network

ZaRide is a modern Peer-to-Peer (P2P) platform designed for riders, drivers, passengers, and parcel senders across Nigeria and beyond. It connects people traveling between cities and neighborhoods directly with peer riders who have available passenger seats or spare cargo space — with zero platform middleman markups.

---

## 🚀 Key Features

### 1. 🚗 Passenger Travel & Carpooling
- **Search & Filter:** Find rides by origin city, destination, departure date, and vehicle type.
- **Seat Reservations:** Book 1 to 4+ passenger seats with transparent, direct unit pricing.
- **Trip Publishing for Drivers:** Vehicle owners can post scheduled commutes or intercity travels (origin, destination, departure time, seats available, price per seat).
- **Post Open Travel Requests:** If no ride is currently scheduled, passengers can post an open travel request stating their preferred pickup time and budget.

### 2. 📦 Peer-to-Peer Parcel & Package Deliveries
- **Same-Day Motorcycle Dispatch:** Rapid delivery of documents, electronics, and small packages.
- **Intercity Trunk Cargo:** Send medium, large, and bulky parcels in cars, SUVs, and vans with drivers already traveling that route.
- **Open Parcel Marketplace:** Senders post packages with recipient details and offered budget. Nearby riders can claim and deliver them with 1 click.
- **Delivery routes by Riders:** Drivers specify cargo space dimensions and delivery fees alongside passenger seating.

### 3. 💬 Real-Time In-App P2P Chat
- Every booking generates a direct, dedicated chat room between the passenger/sender and the driver.
- Real-time communication for pickup landmark coordination, arrival updates, and handover confirmation.
- Direct phone call and WhatsApp integration shortcuts.

### 4. 🛡️ Trust, Ratings & Safety
- **Verified Driver Profiles:** Displays vehicle make/model, color, verified license plate number, and photos.
- **Mutual Reputation:** Customers leave 1-to-5 star ratings and reviews upon journey completion.
- **Online/Offline Availability Beacon:** Live status indicators so customers know which riders are actively taking trips.

---

## 🛠️ Requirements & Setup

### Requirements
- **PHP 8.0+** with PDO MySQL enabled
- **MySQL 5.7+** or **MariaDB 10.4+**
- Apache (XAMPP / WampServer) or built-in PHP CLI server

### Database Setup
1. Import `schema.sql` into MySQL:
   ```sh
   mysql -u root < schema.sql
   ```
2. If upgrading an existing installation, run `migration_v2_p2p.sql` to apply the P2P tables and demo seed data:
   ```sh
   mysql -u root zaride < migration_v2_p2p.sql
   ```
3. Configuration settings (via environment variables if needed):
   - `ZARIDE_DB_HOST` (default: `127.0.0.1`)
   - `ZARIDE_DB_NAME` (default: `Zaride`)
   - `ZARIDE_DB_USER` (default: `root`)
   - `ZARIDE_DB_PASSWORD` (default: empty)

---

## ⚡ Demo Accounts for Testing

All seeded demo accounts use password: `password123`

| Role | Name | Email | Features |
|---|---|---|---|
| **Rider / Driver** | Bukar Samuel | `bukarsamuelisaac@gmail.com` | Online driver, active trips, car |
| **Rider / Driver** | David Adeyemi | `david.adeyemi@zaride.example` | Active Lagos trips, high rating |
| **Rider / Driver** | Fatima Bello | `fatima.bello@zaride.example` | Intercity Abuja-Kaduna SUV trips |
| **Rider / Courier**| Chinedu Okafor | `chinedu.okafor@zaride.example` | Express motorcycle dispatch |
| **Customer** | Amaka Johnson | `amaka.customer@zaride.example` | Passenger & sender account |
| **Customer** | Tunde Bakare | `tunde.customer@zaride.example` | Customer with open parcel request |

---

## 📁 File Structure

- `index.php` — Flagship landing page with multi-mode P2P search console, live routes, and registration.
- `travels.php` — Dedicated passenger carpooling & travel search and booking page.
- `deliveries.php` — Dedicated parcel delivery dispatch, route listings, and open customer request feed.
- `zaride.php` — Peer rider directory with online beacons, vehicle specifications, and ratings.
- `book.php` — Transactional booking processor for seats, parcels, and open customer requests.
- `dashboard.php` — Unified command center for riders (route publisher, booking manager, marketplace claim) and customers (bookings tracking, open requests).
- `messages.php` — Real-time in-app P2P Messenger with coordination shortcuts.
- `review.php` — Rating and feedback review system that updates rider reputation stats.
- `request-action.php` — Fast controller for accepting open requests and updating booking lifecycles.
- `login.php` / `logout.php` — Secure authentication with role switcher and quick demo login shortcuts.
- `rider-file.php` — Secure handler for private vehicle and plate documentation images.
- `config.php` — Database connector, session security, CSRF protection, and utility helpers.
- `styles.css` — Modern design system with responsive layouts, typography, and card components.
