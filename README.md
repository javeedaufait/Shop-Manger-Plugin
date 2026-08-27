# Shop Onboarding Manager

WordPress plugin MVP foundation for onboarding supermarkets and grocery shops into Nearmart.

## Features
- **Mobile-First Frontend Onboarding Form**: `/onboard-shop/` with real-time duplicate detection, GPS capture / manual fallback, photo upload to WP Media Library, and optional merchant account creation.
- **Shop Data Model**: Custom Post Type `shop`, custom taxonomy `shop_status` (Contacted, Interested, Verified, Committed, Rejected), and registered post meta (`som_`).
- **Merchant Account System**: Native `merchant` WP user role, username + password login at `/merchant-login/`, blocked `wp-admin` access.
- **Frontend Merchant Dashboard**: `/merchant-dashboard/` with shop details confirmation, participation agreement (`v1.0`), automatic `Committed` status transition, and data correction requests.
- **Admin Management System**: WP Admin -> **Shop Onboarding** with dynamic statistic cards, Shop Tracker list table with search and filters, Follow-ups schedule with overdue highlighting, and Change Request review/approval.