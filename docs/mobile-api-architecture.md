# NearMart Mobile Application Architecture & REST API Specification
**Phase APP-1: Core Architecture, API Contracts & Decoupling Strategy**

---

## 1. Executive Summary & Objectives

This document defines the clean, decoupled backend REST API architecture for the NearMart mobile ecosystem, preparing the WordPress/WooCommerce backend to support two standalone React Native applications:
1. **NearMart Customer App**: Neighborhood discovery, grocery browsing, cart building, advance ordering for queue-free pickup.
2. **NearMart Merchant App**: Mobile catalog management, price/stock updates, standalone product creation, product request submissions, and order fulfillment.

### Architectural Imperative
```
┌─────────────────────────────────────────────────────────────────────────┐
│                     React Native Mobile Applications                     │
│               [ Customer App ]           [ Merchant App ]               │
└────────────────────────────────────┬────────────────────────────────────┘
                                     │ HTTPS / Bearer Token / JSON
                                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                    NearMart Unified REST API Layer                      │
│                      Namespace: /wp-json/nearmart/v1/                   │
│   - Unified Response Envelope        - Decoupled DTO Format             │
│   - JWT / Stateless Bearer Auth      - Multilingual Filter (en / ml)    │
└────────────────────────────────────┬────────────────────────────────────┘
                                     │
                                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      NearMart Business Logic Layer                      │
│  - SOM_Catalog_Repository           - SOM_Product_Request_Repository    │
│  - SOM_Merchant_Manager             - SOM_Catalog_Permissions          │
│  - SOM_Master_Product (Polylang)    - Future Order / Cart Manager       │
└────────────────────────────────────┬────────────────────────────────────┘
                                     │
                                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                   WordPress / WooCommerce / Custom DB                   │
│  - wp_posts (CPT 'shop')            - wp_nearmart_shop_products         │
│  - wp_posts (CPT 'product')         - wp_nearmart_product_requests      │
│  - wp_users & wp_usermeta           - Future: wp_nearmart_orders        │
└─────────────────────────────────────────────────────────────────────────┘
```

> **Strict Decoupling Rule**: Mobile apps must **never** connect directly to the WooCommerce REST API (`/wp-json/wc/v3/`) or consume raw WooCommerce post/meta structures. All mobile data flows through the `/wp-json/nearmart/v1/` namespace via controlled Data Transfer Objects (DTOs).

---

## 2. Current System & Data Audit

### 2.1 Post Types & Data Entities
* **Shops (`shop` / `shop_onboarding`)**:
  - WordPress Custom Post Type storing physical store profiles.
  - Custom Meta: `som_address`, `som_latitude`, `som_longitude`, `som_phone_number`, `som_owner_name`, `som_shop_photo_id`, `som_verified`, `som_shop_type`.
* **Master Products (WooCommerce `product`)**:
  - Centralized catalog of standard grocery SKUs maintained by administrators.
  - Taxonomies: `product_cat` (Categories with Polylang multilingual translations), `brand`.
  - Multilingual names and descriptions stored via standard Polylang language associations and post meta overrides.
* **Hybrid Shop Catalog (`wp_nearmart_shop_products`)**:
  - Custom relational table mapping products to individual shops with store-level pricing and stock.
  - Supports **Master-Linked Products** (`product_id > 0`, referencing WooCommerce master product).
  - Supports **Standalone Shop Products** (`product_id IS NULL`, custom unlinked store items with `custom_name`, `custom_category`, `custom_brand`, `custom_unit`, `custom_barcode`).
* **Product Requests (`wp_nearmart_product_requests`)**:
  - Tracks merchant requests for new master items missing from the central catalog.
  - Statuses: `pending`, `reviewed`, `approved`, `completed`, `rejected`.
* **Merchant Accounts**:
  - WordPress Users with role `merchant` linked to a shop via `som_shop_id` user meta.

---

## 3. Standardized NearMart API Response Contracts

To maintain complete consistency across all mobile endpoints, every REST response conforms to a deterministic envelope structure.

### 3.1 Standard Success Envelope
```json
{
  "success": true,
  "data": {},
  "message": "Operation completed successfully."
}
```
* For lists / paginated collections:
```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 142,
      "total_pages": 8
    }
  },
  "message": ""
}
```

### 3.2 Standard Error Envelope
```json
{
  "success": false,
  "error_code": "RESOURCE_NOT_FOUND",
  "message": "The requested shop or product was not found.",
  "details": {
    "resource_id": 42
  }
}
```

### 3.3 HTTP Status Code Mapping
| Status Code | Meaning | Use Case |
|---|---|---|
| `200 OK` | Request succeeded | Standard GET, PUT, PATCH responses |
| `201 Created` | Resource created | Successful POST creation (product request, standalone product) |
| `400 Bad Request` | Validation failure | Missing required fields, invalid format |
| `401 Unauthorized` | Unauthenticated | Missing or expired Bearer token |
| `403 Forbidden` | Access denied | Merchant attempting to modify another shop's catalog |
| `404 Not Found` | Not found | Shop, product, or request ID does not exist |
| `409 Conflict` | Duplicate state | Barcode/SKU duplicate collision |
| `500 Server Error` | Backend failure | Database exception or internal error |

---

## 4. REST API Endpoint Audit & Reusability

### 4.1 Existing Reusable Endpoints (`nearmart/v1`)
The following endpoints are already implemented in `class-som-rest-api.php` and ready for mobile consumption:

| Endpoint | Method | Role | Description |
|---|---|---|---|
| `/wp-json/nearmart/v1/shops` | `GET` | Public | List published shops with search & pagination |
| `/wp-json/nearmart/v1/shops/{shop_id}` | `GET` | Public | Get full details for a single shop |
| `/wp-json/nearmart/v1/shops/{shop_id}/products` | `GET` | Public | Get active product catalog for a shop (supports `lang=en|ml`, search, category) |
| `/wp-json/nearmart/v1/products/{product_id}` | `GET` | Public | Get details of a master or standalone product (with optional `shop_id` context) |

---

## 5. Missing Mobile API Endpoints

### 5.1 Missing Endpoints: Customer Mobile App

```
Customer Mobile App Endpoints Roadmap:
├── Auth & Profile
│   ├── POST /nearmart/v1/auth/customer/login-otp      (Request mobile OTP)
│   ├── POST /nearmart/v1/auth/customer/verify-otp     (Verify OTP & issue JWT)
│   ├── GET  /nearmart/v1/customer/profile             (Get customer profile)
│   └── PUT  /nearmart/v1/customer/profile             (Update name, phone, preferences)
├── Discovery & Navigation
│   ├── GET  /nearmart/v1/shops/nearby                 (Find shops by lat/lng radius)
│   ├── GET  /nearmart/v1/categories                   (List master categories in en/ml)
│   └── GET  /nearmart/v1/search/products              (Search products across all nearby shops)
└── Future Cart & Orders (Phase APP-2+)
    ├── POST /nearmart/v1/cart/items                   (Add/update item in cart)
    ├── GET  /nearmart/v1/cart                         (Get active cart with shop validation)
    ├── POST /nearmart/v1/orders                       (Place queue-free pickup order)
    ├── GET  /nearmart/v1/orders                       (Customer order history)
    └── GET  /nearmart/v1/orders/{order_id}            (Live pickup status & QR code)
```

#### Detailed Customer Endpoint Contracts:

#### `GET /wp-json/nearmart/v1/shops/nearby`
* **Query Parameters**:
  - `lat` (float, required): Customer latitude (e.g. `10.8505`)
  - `lng` (float, required): Customer longitude (e.g. `76.2711`)
  - `radius` (float, optional, default: `10` km): Search radius
  - `lang` (string, optional, default: `en`): `en` or `ml`
* **Response `data`**:
  ```json
  {
    "shops": [
      {
        "shop_id": 77,
        "name": "Rismi Supermarket",
        "shop_type": "Supermarket",
        "address": "Main Road, Angamaly",
        "distance_km": 1.4,
        "photo_url": "https://nearmart.local/wp-content/uploads/shop1.jpg",
        "status": "verified",
        "is_open": true
      }
    ]
  }
  ```

#### `GET /wp-json/nearmart/v1/categories`
* **Query Parameters**: `lang` (`en` or `ml`)
* **Response `data`**:
  ```json
  {
    "categories": [
      {
        "id": 12,
        "slug": "dairy-eggs",
        "name": "Dairy & Eggs",
        "localized_name": "പാൽ & മുട്ട",
        "image_url": "https://nearmart.local/wp-content/uploads/cat-dairy.jpg"
      }
    ]
  }
  ```

---

### 5.2 Missing Endpoints: Merchant Mobile App

```
Merchant Mobile App Endpoints Roadmap:
├── Auth & Session
│   ├── POST /nearmart/v1/auth/merchant/login          (Username/password -> JWT)
│   ├── POST /nearmart/v1/auth/refresh                 (Refresh access token)
│   └── GET  /nearmart/v1/merchant/profile             (Merchant profile & linked shop info)
├── Dashboard & Metrics
│   └── GET  /nearmart/v1/merchant/dashboard           (Summary stats: total, active, out of stock)
├── Catalog Operations
│   ├── GET    /nearmart/v1/merchant/catalog           (Merchant's full shop catalog)
│   ├── POST   /nearmart/v1/merchant/catalog/master    (Add WooCommerce master product to shop)
│   ├── POST   /nearmart/v1/merchant/catalog/standalone(Create new standalone shop product)
│   ├── PATCH  /nearmart/v1/merchant/catalog/{id}      (Update price, stock, status, SKU)
│   ├── DELETE /nearmart/v1/merchant/catalog/{id}      (Remove product from shop catalog)
│   └── GET    /nearmart/v1/merchant/master-products   (Search master catalog to add items)
└── Product Requests
    ├── GET  /nearmart/v1/merchant/product-requests    (List merchant's product requests)
    └── POST /nearmart/v1/merchant/product-requests    (Submit request for new master item)
```

#### Detailed Merchant Endpoint Contracts:

#### `POST /wp-json/nearmart/v1/auth/merchant/login`
* **Body**:
  ```json
  {
    "username": "anita_mart",
    "password": "SecretPassword123"
  }
  ```
* **Response `data`**:
  ```json
  {
    "token": "eyJhbGciOiJIUzI1NiIsIn...",
    "refresh_token": "def502009...",
    "user": {
      "id": 14,
      "username": "anita_mart",
      "display_name": "Anita Kumar",
      "shop_id": 77,
      "shop_name": "Corner Fresh Mart"
    }
  }
  ```

#### `PATCH /wp-json/nearmart/v1/merchant/catalog/{id}`
* **Headers**: `Authorization: Bearer <TOKEN>`
* **Body**:
  ```json
  {
    "price": 54.00,
    "sale_price": 49.50,
    "stock_status": "instock",
    "stock_quantity": 25,
    "status": "active",
    "shop_sku": "CFM-MILK-01"
  }
  ```
* **Response `data`**:
  ```json
  {
    "id": 105,
    "shop_id": 77,
    "title": "Milma Toned Milk 500ml",
    "price": 54.00,
    "sale_price": 49.50,
    "stock_status": "instock",
    "status": "active",
    "updated_at": "2026-09-01T17:15:00Z"
  }
  ```

#### `POST /wp-json/nearmart/v1/merchant/product-requests`
* **Headers**: `Authorization: Bearer <TOKEN>`
* **Body**:
  ```json
  {
    "product_name": "Brahmins Sambar Powder 250g",
    "brand": "Brahmins",
    "category": "Spices & Masalas",
    "unit": "250g",
    "barcode": "8904012345678",
    "notes": "High demand among neighborhood customers."
  }
  ```
* **Response `data`**:
  ```json
  {
    "request_id": 31,
    "status": "pending",
    "created_at": "2026-09-01T17:15:00Z"
  }
  ```

---

## 6. Authentication & Security Architecture

### 6.1 Authentication Mechanism
Mobile apps require stateless authentication using cryptographically signed **JWT (JSON Web Tokens)** or **Bearer Tokens**:

```
[ Mobile App ] --(1) POST /auth/login {creds}---------> [ NearMart REST API ]
[ Mobile App ] <--(2) Return Access Token (1h) & Refresh (30d) -- [ NearMart REST API ]
[ Mobile App ] --(3) Store in OS Keychain/SecureStore
[ Mobile App ] --(4) API Request (Header: "Authorization: Bearer <Token>") -->
```

### 6.2 Token Security Policies
1. **Access Token Lifespan**: 60 minutes.
2. **Refresh Token Lifespan**: 30 days (stored in encrypted mobile Keychain/SecureStore).
3. **Revocation & Invalidation**: Token signature verification includes `user_id`, `role`, and `token_version` timestamp stored in `wp_usermeta`. Changing a password or admin revocation immediately invalidates all active tokens.
4. **Transport Security**: Strictly enforced HTTPS (`is_ssl()`).

---

## 7. Role-Based Access Control (RBAC) Matrix

| Entity / Action | Public / Guest | Customer (`customer`) | Merchant (`merchant`) | Admin (`administrator`) |
|---|:---:|:---:|:---:|:---:|
| Browse Shops & Products | ✅ Allowed | ✅ Allowed | ✅ Allowed | ✅ Allowed |
| Place Pickup Order | ❌ Forbidden | ✅ Allowed | ❌ Forbidden | ✅ Allowed |
| View Customer Orders | ❌ Forbidden | ✅ Own Orders | ❌ Forbidden | ✅ All Orders |
| View Merchant Dashboard | ❌ Forbidden | ❌ Forbidden | ✅ Linked Shop Only | ✅ All Shops |
| Edit Shop Catalog Prices/Stock | ❌ Forbidden | ❌ Forbidden | ✅ Linked Shop Only | ✅ All Shops |
| Add Standalone / Master Item | ❌ Forbidden | ❌ Forbidden | ✅ Linked Shop Only | ✅ All Shops |
| Submit Product Request | ❌ Forbidden | ❌ Forbidden | ✅ Linked Shop Only | ✅ Allowed |
| Approve / Fulfill Requests | ❌ Forbidden | ❌ Forbidden | ❌ Forbidden | ✅ Allowed |

---

## 8. Database Architecture Blueprint: Future Carts & Orders (Phase APP-2+)

To ensure complete scalability without straining WooCommerce standard single-store checkout tables, NearMart will implement specialized custom relational tables for multi-shop pickup coordination.

### 8.1 Future Table: `wp_nearmart_carts` & `wp_nearmart_cart_items`
```sql
-- Active Customer Carts (Scoped strictly per shop)
CREATE TABLE wp_nearmart_carts (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned DEFAULT NULL,     -- NULL for guest session tokens
    session_key varchar(64) DEFAULT NULL,        -- Mobile UUID / device key
    shop_id bigint(20) unsigned NOT NULL,        -- Strict single-shop-per-cart constraint
    status varchar(20) NOT NULL DEFAULT 'active', -- active, converted, abandoned
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY session_key (session_key),
    KEY shop_id (shop_id)
);

CREATE TABLE wp_nearmart_cart_items (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    cart_id bigint(20) unsigned NOT NULL,
    shop_product_id bigint(20) unsigned NOT NULL, -- References wp_nearmart_shop_products.id
    quantity int(11) NOT NULL DEFAULT 1,
    unit_price decimal(10,2) NOT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY cart_id (cart_id),
    KEY shop_product_id (shop_product_id)
);
```

### 8.2 Future Table: `wp_nearmart_orders` & `wp_nearmart_order_items`
```sql
-- High-Performance Pickup Orders
CREATE TABLE wp_nearmart_orders (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_number varchar(32) NOT NULL,            -- e.g. NM-20260901-7701
    customer_id bigint(20) unsigned NOT NULL,
    shop_id bigint(20) unsigned NOT NULL,
    total_amount decimal(10,2) NOT NULL,
    status varchar(30) NOT NULL DEFAULT 'pending', 
    -- Statuses: pending, confirmed, packing, ready_for_pickup, completed, cancelled
    pickup_code varchar(10) NOT NULL,             -- 4 or 6 digit verification code / QR
    customer_notes text DEFAULT NULL,
    merchant_notes text DEFAULT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_at datetime DEFAULT NULL,
    completed_at datetime DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY order_number (order_number),
    KEY customer_id (customer_id),
    KEY shop_id (shop_id),
    KEY status (status)
);

CREATE TABLE wp_nearmart_order_items (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_id bigint(20) unsigned NOT NULL,
    shop_product_id bigint(20) unsigned NOT NULL,
    product_name varchar(255) NOT NULL,           -- Snapshot title at purchase time
    unit varchar(100) DEFAULT NULL,
    price decimal(10,2) NOT NULL,
    quantity int(11) NOT NULL,
    subtotal decimal(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY order_id (order_id)
);
```

### 8.3 Core Order Lifecycle Rules
1. **Single-Shop Order Constraint**: Because NearMart provides queue-free neighborhood pickup, a single order checkout is always fulfilled by a single physical shop.
2. **Real-Time Stock Decrement**: Transitioning an order to `confirmed` automatically updates `stock_quantity` in `wp_nearmart_shop_products`.
3. **Queue-Free Handshake**: The customer presents the `pickup_code` or scans the QR code at the merchant counter; merchant marks order `completed` in the Merchant App.

---

## 9. Implementation Roadmap

| Phase | Milestone | Scope |
|---|---|---|
| **Phase APP-1 (Current)** | **Architecture & API Contracts** | Complete audit, response envelope standardization, endpoint mapping, permissions & schema documentation. |
| **Phase APP-2** | **Merchant App REST APIs** | Implement Merchant Auth (JWT), Catalog CRUD endpoints, and Product Request API in plugin backend. |
| **Phase APP-3** | **Customer Discovery & Search APIs** | Implement Geolocation discovery (`/shops/nearby`), Categories endpoint, and cross-shop search. |
| **Phase APP-4** | **Cart, Orders & Pickup Engine** | Implement custom tables, Cart API, Order placement, and status transition webhooks. |
| **Phase APP-5** | **React Native Apps Development** | Build NearMart Customer & Merchant React Native mobile codebases using defined REST endpoints. |
---

## 10. Phase APP-2 Implementation: Authentication & Profile REST API

The mobile authentication layer has been fully implemented in `includes/class-som-mobile-auth.php`.

### 10.1 Token Lifecycle & Security
1. **Stateless JWT-compatible Bearer Tokens**:
   - Signature: HMAC-SHA256 using WordPress `wp_salt('auth')`.
   - Payload contains: `uid` (User ID), `role`, `iat` (Issued at), `exp` (30 days expiration), `ver` (Token version for instant revocation), and `jti` (Unique random token ID).
2. **WordPress Integration**:
   - Filter `determine_current_user` extracts `Authorization: Bearer <token>` from HTTP request headers.
   - Automatically populates `wp_get_current_user()`, `get_current_user_id()`, and capability checks without session cookies.
3. **Instant Token Revocation**:
   - Calling `POST /auth/logout` or changing passwords increments `som_auth_token_version` in `wp_usermeta`, immediately invalidating all previously issued tokens.

### 10.2 Implemented Endpoints Reference

| Endpoint | Method | Permission | Payload / Params | Description |
|---|---|---|---|---|
| `/wp-json/nearmart/v1/auth/register` | `POST` | Public | `name`, `email`, `password`, `phone` (optional) | Registers new `customer` account & issues Bearer token |
| `/wp-json/nearmart/v1/auth/login` | `POST` | Public | `username` (or email), `password` | Authenticates Customer, Merchant, or Admin & returns user profile + token |
| `/wp-json/nearmart/v1/auth/logout` | `POST` | Authenticated | None (Bearer Header) | Revokes active mobile tokens |
| `/wp-json/nearmart/v1/auth/me` | `GET` | Authenticated | None (Bearer Header) | Returns profile. For merchants, automatically attaches linked shop object |
| `/wp-json/nearmart/v1/auth/profile` | `PUT` | Authenticated | `name`, `first_name`, `last_name`, `email`, `phone`, `current_password`, `new_password` | Updates user details. Issues new token if password changes |