# LoopBuy

LoopBuy is a custom WordPress theme for a second-hand marketplace — a platform where users can browse, buy, sell, and message each other about pre-loved items, with a strong emphasis on trust and safety (AI-assisted scam screening, smart pricing suggestions, verified listings, and buyer/seller reviews).

Built on the `_s` (underscores) starter theme, LoopBuy replaces the generic scaffold with a full set of custom page templates, a custom post type for listings, and client-side cart/wishlist/chat functionality.

## Features

- **Homepage / Marketplace browse** (`index.php`) — searchable, filterable product grid with category pills, price range, condition, and location filters.
- **Product detail page** (`page-product-detail.php`) — full listing view with images, price, seller info, "safety screened" badge, Add to Cart, Save, and Chat actions, plus product and seller review sections.
- **Sell / Post a listing** (`page-sell.php`) — multi-field listing form (photos, title, price, brand, category, condition, location, description) with an AI price recommendation panel and AI scam-detection scan button. Creates a `loopbuy_listing` custom post type entry on submit.
- **My Listings** (`page-my-listings.php`) — seller dashboard to view, edit, mark-as-sold, or delete their own listings.
- **Cart** (`page-cart.php`) — quantity controls, running total, and checkout button; cart state is stored client-side so it works for guests too.
- **Saved / Wishlist** (`page-saved.php`) — heart-to-save favourite listings.
- **Messages** (`page-messages.php`) — per-listing chat interface with conversation history, opened from a product's Chat button.
- **Orders** (`page-orders.php`) — order history with linked products and "Message seller" shortcuts.
- **Account pages** — Login and Register (`page-login.php`, `page-register.php`) with nonce-protected forms, validation, and a "Continue with Google" placeholder; Profile (`page-profile.php`) for editing name, phone, location, bio, and avatar, plus a summary of the user's listings.
- **About & Contact** (`page-about.php`, `page-contact.php`) — marketing/info page highlighting AI scam detection, smart pricing, real-time chat, and trusted reviews; contact form that emails the site admin.
- **Dark mode toggle**, header search, and live cart/saved badge counts, available sitewide via `header.php` / `footer.php`.

## How it's built

- **Platform:** WordPress theme (PHP + the standard WordPress template hierarchy: `header.php`, `footer.php`, `page-*.php` templates, `functions.php`).
- **Data:**
  - Listings are a registered custom post type (`loopbuy_listing`) with meta fields for price, brand, category, condition, location, and status (active/sold).
  - Users are standard WordPress users/subscribers, extended with usermeta (phone, location, bio, avatar).
  - Demo/sample product data used across the browse, detail, cart, saved, and messages pages is centralised in `inc/product-data.php`.
- **Frontend interactivity:** vanilla JavaScript (no framework) for cart, saved items, chat history, and dark mode, using `localStorage` so guests can shop without an account.
- **Security:** WordPress nonces on all forms (login, register, sell, contact, profile), input sanitisation/escaping (`sanitize_text_field`, `esc_html`, `esc_attr`, etc.) throughout.
- **Styling:** `style.css` (theme stylesheet with the required WordPress theme header).

## Project structure

```
loopbuy/
├── style.css                 # Theme stylesheet + theme header
├── functions.php             # Theme setup, enqueue scripts/styles, CPT registration
├── header.php / footer.php   # Global site chrome (nav, search, cart/saved badges, dark mode)
├── index.php                 # Homepage — product browse/search/filter
├── page-product-detail.php   # Single product view
├── page-sell.php             # Create/post a listing
├── page-my-listings.php      # Seller's own listings dashboard
├── page-cart.php             # Shopping cart
├── page-saved.php            # Saved/wishlist items
├── page-messages.php         # Buyer–seller chat
├── page-orders.php           # Order history
├── page-login.php            # Login
├── page-register.php         # Registration
├── page-profile.php          # Account/profile settings
├── page-about.php            # About/marketing page
├── page-contact.php          # Contact form
└── inc/
    └── product-data.php      # Shared demo product data
```

## Getting started

1. Copy the theme folder into `wp-content/themes/` in a WordPress install.
2. Activate **LoopBuy** from *Appearance → Themes*.
3. In *wp-admin*, create Pages with slugs matching the templates above (`sell`, `cart`, `saved`, `messages`, `orders`, `login`, `register`, `profile`, `about`, `contact`, `my-listings`) — WordPress will automatically pick up the matching `page-*.php` template for each.
4. (Optional) Assign a menu to the **Primary** location under *Appearance → Menus*.

## Notes / next steps

- Product, cart, and saved-item data currently live in demo arrays / `localStorage` for frontend demonstration — a backend teammate can wire these to the database (WooCommerce-style order/cart tables, or custom tables) without changing the templates' markup.
- Listing delete/mark-as-sold actions call `admin-ajax.php` actions (`loopbuy_delete_listing`, `loopbuy_mark_listing_sold`) that still need to be implemented server-side.
- "Continue with Google" and the AI price-suggestion / scam-detection buttons are UI placeholders pending integration with real auth and AI services.