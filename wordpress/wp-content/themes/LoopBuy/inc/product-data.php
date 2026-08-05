<?php

/**
 * LoopBuy Product Data
 *
 * Bundled product fixtures for frontend development and backend outages.
 * When the LoopBuy Backend Bridge is available, its normalized API catalogue
 * replaces this array at the bottom of the file.
 *
 * @package LoopBuy
 */

if ( ! function_exists( 'loopbuy_product_image_url' ) ) {
	/**
	 * Resolve either an absolute API image URL or a bundled fixture filename.
	 *
	 * @param array $product Product view model.
	 * @return string
	 */
	function loopbuy_product_image_url( $product ) {
		if ( ! is_array( $product ) ) {
			return '';
		}

		$image = '';

		if ( isset( $product['image_url'] ) && is_string( $product['image_url'] ) ) {
			$image = trim( $product['image_url'] );
		}

		if ( '' === $image && isset( $product['image'] ) && is_string( $product['image'] ) ) {
			$image = trim( $product['image'] );
		}

		if ( '' === $image ) {
			return '';
		}

		if ( function_exists( 'loopbuy_backend_public_image_url' ) ) {
			$backend_image = loopbuy_backend_public_image_url( $image );

			if ( '' !== $backend_image ) {
				return $backend_image;
			}
		}

		if ( preg_match( '#^https?://#i', $image ) ) {
			return esc_url_raw( $image, array( 'http', 'https' ) );
		}

		// Fixture images must be plain filenames, never caller-controlled paths.
		if ( preg_match( '#[\\\\/]#', $image ) ) {
			return '';
		}

		return trailingslashit( get_template_directory_uri() . '/images' ) . rawurlencode( $image );
	}
}

$products = [

    /* =========================
       ELECTRONICS
    ========================= */

    [
        'id' => 1,
        'name' => 'iPhone 14 Pro',
        'brand' => 'Apple',
        'price' => 750,
        'location' => 'Orchard',
        'condition' => 'Like New',
        'category' => 'electronics',
        'image' => 'iphone.webp',
        'description' => 'iPhone 14 Pro in excellent condition with smooth performance and a high-quality camera. Suitable for everyday use, photography and work.',
        'seller' => 'Alex Tan',
        'views' => 28,
        'verified' => false,
    ],

    [
        'id' => 2,
        'name' => 'Wireless Headphones',
        'brand' => 'Sony',
        'price' => 120,
        'location' => 'Bishan',
        'condition' => 'Good',
        'category' => 'electronics',
        'image' => 'wireless_headphone.jpeg',
        'description' => 'Sony wireless headphones in good working condition with clear sound, comfortable ear cushions and reliable battery life.',
        'seller' => 'Sarah Lim',
        'views' => 17,
        'verified' => false,
    ],

    [
        'id' => 3,
        'name' => 'Samsung Galaxy S23',
        'brand' => 'Samsung',
        'price' => 650,
        'location' => 'Tampines',
        'condition' => 'Like New',
        'category' => 'electronics',
        'image' => 'Samsung_Galaxy_S23.webp',
        'description' => 'Samsung Galaxy S23 in like-new condition. Fast performance, excellent display and great camera quality.',
        'seller' => 'Daniel Lee',
        'views' => 35,
        'verified' => false,
    ],

    [
        'id' => 4,
        'name' => 'MacBook Air M2',
        'brand' => 'Apple',
        'price' => 980,
        'location' => 'Jurong East',
        'condition' => 'Good',
        'category' => 'electronics',
        'image' => 'MacBook_Air_M2.avif',
        'description' => 'MacBook Air M2 in good condition. Lightweight and suitable for study, office work, programming and everyday use.',
        'seller' => 'Michael Ong',
        'views' => 42,
        'verified' => false,
    ],


    /* =========================
       FASHION
    ========================= */

    [
        'id' => 5,
        'name' => 'Leather Jacket',
        'brand' => 'Zara',
        'price' => 85,
        'location' => 'Tampines',
        'condition' => 'Like New',
        'category' => 'fashion',
		'image' => 'Leather_Jacket.jpg',
        'description' => 'Stylish Zara leather jacket in like-new condition. Comfortable fit and suitable for casual or evening wear.',
        'seller' => 'Emma Wong',
        'views' => 21,
        'verified' => false,
    ],

    [
        'id' => 6,
        'name' => 'Nike Running Shoes',
        'brand' => 'Nike',
        'price' => 95,
        'location' => 'Jurong',
        'condition' => 'Good',
        'category' => 'fashion',
        'image' => 'Nike_Running_Shoes.avif',
        'description' => 'Nike running shoes in good condition with comfortable cushioning and lightweight support for running or daily use.',
        'seller' => 'Ryan Koh',
        'views' => 19,
        'verified' => false,
    ],

    [
        'id' => 7,
        'name' => 'Denim Jacket',
        'brand' => 'Levi\'s',
        'price' => 60,
        'location' => 'Bugis',
        'condition' => 'Good',
        'category' => 'fashion',
        'image' => 'Denim_Jacket.webp',
        'description' => 'Classic Levi\'s denim jacket in good condition. Easy to match with casual outfits and suitable for everyday wear.',
        'seller' => 'Jason Tan',
        'views' => 15,
        'verified' => false,
    ],

    [
        'id' => 8,
        'name' => 'Classic Handbag',
        'brand' => 'Charles & Keith',
        'price' => 55,
        'location' => 'Woodlands',
        'condition' => 'Like New',
        'category' => 'fashion',
        'image' => 'charles__keith_black_handbag.jpg',
        'description' => 'Charles & Keith handbag in like-new condition with a clean interior and elegant design for everyday use.',
        'seller' => 'Chloe Lim',
        'views' => 26,
        'verified' => false,
    ],


    /* =========================
       GAMING
    ========================= */

    [
        'id' => 9,
        'name' => 'PlayStation 5',
        'brand' => 'Sony',
        'price' => 480,
        'location' => 'Punggol',
        'condition' => 'Like New',
        'category' => 'gaming',
        'image' => 'PlayStation5.jpg',
        'description' => 'PlayStation 5 in excellent condition with smooth performance. Ideal for next-generation console gaming.',
        'seller' => 'Ethan Ng',
        'views' => 54,
        'verified' => false,
    ],

    [
        'id' => 10,
        'name' => 'Nintendo Switch OLED',
        'brand' => 'Nintendo',
        'price' => 320,
        'location' => 'Serangoon',
        'condition' => 'Good',
        'category' => 'gaming',
        'image' => 'Nintendo_Switch_OLED.avif',
        'description' => 'Nintendo Switch OLED in good condition with a bright display and portable gaming support.',
        'seller' => 'Lucas Chen',
        'views' => 31,
        'verified' => false,
    ],

    [
        'id' => 11,
        'name' => 'Xbox Series S',
        'brand' => 'Microsoft',
        'price' => 280,
        'location' => 'Hougang',
        'condition' => 'Good',
        'category' => 'gaming',
        'image' => 'Xbox_Series_S.jpg',
        'description' => 'Xbox Series S in good working condition. Compact console with fast loading and digital game support.',
        'seller' => 'Noah Lim',
        'views' => 24,
        'verified' => false,
    ],

    [
        'id' => 12,
        'name' => 'Gaming Mechanical Keyboard',
        'brand' => 'Razer',
        'price' => 80,
        'location' => 'Clementi',
        'condition' => 'Like New',
        'category' => 'gaming',
        'image' => 'Gaming_Mechanical_Keyboard.jpg',
        'description' => 'Razer mechanical gaming keyboard in like-new condition with responsive switches and comfortable key spacing.',
        'seller' => 'Marcus Lee',
        'views' => 18,
        'verified' => false,
    ],


    /* =========================
       SPORTS
    ========================= */

    [
        'id' => 13,
        'name' => 'Mountain Bike Trek',
        'brand' => 'Trek',
        'price' => 320,
        'location' => 'East Coast',
        'condition' => 'Like New',
        'category' => 'sports',
        'image' => 'Mountain_Bike_Trek.webp',
        'description' => 'Trek mountain bike in like-new condition. Suitable for park riding, trails and casual cycling.',
        'seller' => 'Aaron Goh',
        'views' => 29,
        'verified' => false,
    ],

    [
        'id' => 14,
        'name' => 'Wilson Tennis Racket',
        'brand' => 'Wilson',
        'price' => 70,
        'location' => 'Bishan',
        'condition' => 'Good',
        'category' => 'sports',
        'image' => 'Wilson_Tennis_Racket.webp',
        'description' => 'Wilson tennis racket in good condition with a comfortable grip. Suitable for beginner and intermediate players.',
        'seller' => 'Kevin Tan',
        'views' => 14,
        'verified' => false,
    ],

    [
        'id' => 15,
        'name' => 'Adidas Football',
        'brand' => 'Adidas',
        'price' => 35,
        'location' => 'Yishun',
        'condition' => 'Good',
        'category' => 'sports',
        'image' => 'Adidas_Football.jpg',
        'description' => 'Adidas football in good condition and suitable for casual matches, training or recreational use.',
        'seller' => 'Ben Lim',
        'views' => 11,
        'verified' => false,
    ],

    [
        'id' => 16,
        'name' => 'Yoga Mat Premium',
        'brand' => 'Manduka',
        'price' => 45,
        'location' => 'Novena',
        'condition' => 'Like New',
        'category' => 'sports',
        'image' => 'Yoga_Mat_Premium.jpg',
        'description' => 'Premium Manduka yoga mat in like-new condition with good grip and comfortable cushioning.',
        'seller' => 'Sophia Tan',
        'views' => 16,
        'verified' => false,
    ],


    /* =========================
       HOME APPLIANCES
    ========================= */

    [
        'id' => 17,
        'name' => 'Air Fryer',
        'brand' => 'Philips',
        'price' => 75,
        'location' => 'Ang Mo Kio',
        'condition' => 'Good',
        'category' => 'home-appliances',
        'image' => 'Air_Fryer.webp',
        'description' => 'Philips air fryer in good working condition. Easy to use and suitable for quick everyday cooking.',
        'seller' => 'Grace Lee',
        'views' => 23,
        'verified' => false,
    ],

    [
        'id' => 18,
        'name' => 'Microwave Oven',
        'brand' => 'Panasonic',
        'price' => 50,
        'location' => 'Toa Payoh',
        'condition' => 'Good',
        'category' => 'home-appliances',
        'image' => 'microwave.jpeg',
        'description' => 'Panasonic microwave oven in good condition with simple controls and reliable heating performance.',
        'seller' => 'Henry Lim',
        'views' => 13,
        'verified' => false,
    ],

    [
        'id' => 19,
        'name' => 'Vacuum Cleaner',
        'brand' => 'Dyson',
        'price' => 260,
        'location' => 'Bedok',
        'condition' => 'Like New',
        'category' => 'home-appliances',
        'image' => 'Vacuum_Cleaner.webp',
        'description' => 'Dyson vacuum cleaner in like-new condition with strong suction and convenient cordless cleaning.',
        'seller' => 'Olivia Ng',
        'views' => 36,
        'verified' => false,
    ],

    [
        'id' => 20,
        'name' => 'Coffee Machine',
        'brand' => 'Nespresso',
        'price' => 110,
        'location' => 'Queenstown',
        'condition' => 'Like New',
        'category' => 'home-appliances',
        'image' => 'Coffee_Machine.webp',
        'description' => 'Nespresso coffee machine in like-new condition. Compact design and ideal for making quick coffee at home.',
        'seller' => 'Rachel Tan',
        'views' => 22,
        'verified' => false,
    ],


    /* =========================
       BOOKS
    ========================= */

    [
        'id' => 21,
        'name' => 'Harry Potter Book Set',
        'brand' => 'Bloomsbury',
        'price' => 45,
        'location' => 'Bukit Timah',
        'condition' => 'Good',
        'category' => 'books',
        'image' => 'Harry_Potter_Book_Set_Bloomsbury.jpeg',
        'description' => 'Harry Potter book set published by Bloomsbury. Books are in good readable condition with minor signs of use.',
        'seller' => 'Emily Koh',
        'views' => 20,
        'verified' => false,
    ],

    [
        'id' => 22,
        'name' => 'Atomic Habits',
        'brand' => 'James Clear',
        'price' => 12,
        'location' => 'Clementi',
        'condition' => 'Like New',
        'category' => 'books',
        'image' => 'Atomic_Habits_James_Clear.webp',
        'description' => 'Atomic Habits by James Clear in like-new condition with clean pages and minimal signs of use.',
        'seller' => 'Natalie Lim',
        'views' => 27,
        'verified' => false,
    ],

    [
        'id' => 23,
        'name' => 'Python Programming Book',
        'brand' => 'O\'Reilly',
        'price' => 25,
        'location' => 'Jurong East',
        'condition' => 'Good',
        'category' => 'books',
        'image' => 'Python_Programming_Book.jpeg',
        'description' => 'Python programming reference book in good condition. Useful for students and beginner developers.',
        'seller' => 'David Ong',
        'views' => 19,
        'verified' => false,
    ],

    [
        'id' => 24,
        'name' => 'The Psychology of Money',
        'brand' => 'Morgan Housel',
        'price' => 15,
        'location' => 'Orchard',
        'condition' => 'Like New',
        'category' => 'books',
        'image' => 'The_Psychology_of_Money_.webp',
        'description' => 'The Psychology of Money by Morgan Housel in like-new condition with clean pages and cover.',
        'seller' => 'Amanda Lee',
        'views' => 25,
        'verified' => false,
    ],


    /* =========================
       FURNITURE
    ========================= */

    [
        'id' => 25,
        'name' => 'Wooden Study Desk',
        'brand' => 'IKEA',
        'price' => 90,
        'location' => 'Woodlands',
        'condition' => 'Good',
        'category' => 'furniture',
        'image' => 'Wooden_Desk.jpeg',
        'description' => 'Spacious IKEA wooden study desk in good condition. Suitable for studying, working or a computer setup.',
        'seller' => 'Jonathan Tan',
        'views' => 30,
        'verified' => false,
    ],

    [
        'id' => 26,
        'name' => 'Office Chair',
        'brand' => 'IKEA',
        'price' => 75,
        'location' => 'Tampines',
        'condition' => 'Good',
        'category' => 'furniture',
        'image' => 'Office_Chair.jpeg',
        'description' => 'Comfortable IKEA office chair in good condition with adjustable height and supportive backrest.',
        'seller' => 'Samuel Lim',
        'views' => 18,
        'verified' => false,
    ],

    [
        'id' => 27,
        'name' => 'Three Seater Sofa',
        'brand' => 'Castlery',
        'price' => 350,
        'location' => 'Sengkang',
        'condition' => 'Like New',
        'category' => 'furniture',
        'image' => 'Three_Seater_Sofa.jpg',
        'description' => 'Castlery three-seater sofa in like-new condition. Comfortable seating with a modern design for living rooms.',
        'seller' => 'Michelle Ng',
        'views' => 41,
        'verified' => false,
    ],

    [
        'id' => 28,
        'name' => 'Bedside Table',
        'brand' => 'Muji',
        'price' => 50,
        'location' => 'Pasir Ris',
        'condition' => 'Good',
        'category' => 'furniture',
        'image' => 'Bedside_Table.avif',
        'description' => 'Minimalist Muji bedside table in good condition with useful storage space and a clean wooden finish.',
        'seller' => 'Nicole Tan',
        'views' => 13,
        'verified' => false,
    ],


    /* =========================
       OTHERS
    ========================= */

    [
        'id' => 29,
        'name' => 'Acoustic Guitar',
        'brand' => 'Yamaha',
        'price' => 140,
        'location' => 'Bishan',
        'condition' => 'Good',
        'category' => 'others',
        'image' => 'Acoustic_Guitar.webp',
        'description' => 'Yamaha acoustic guitar in good condition with a warm sound. Suitable for beginners and casual players.',
        'seller' => 'Chris Lee',
        'views' => 22,
        'verified' => false,
    ],

    [
        'id' => 30,
        'name' => 'Digital Camera',
        'brand' => 'Canon',
        'price' => 290,
        'location' => 'Orchard',
        'condition' => 'Like New',
        'category' => 'others',
        'image' => 'Digital_Camera_Canon.jpeg',
        'description' => 'Canon digital camera in like-new condition with clear image quality. Suitable for travel and everyday photography.',
        'seller' => 'Justin Wong',
        'views' => 34,
        'verified' => false,
    ],

    [
        'id' => 31,
        'name' => 'Travel Suitcase',
        'brand' => 'Samsonite',
        'price' => 80,
        'location' => 'Changi',
        'condition' => 'Good',
        'category' => 'others',
        'image' => 'Travel_Suitcase_Samsonite.jpg',
        'description' => 'Samsonite travel suitcase in good condition with smooth wheels, secure zippers and spacious storage.',
        'seller' => 'Rebecca Lim',
        'views' => 16,
        'verified' => false,
    ],

    [
        'id' => 32,
        'name' => 'Electric Guitar',
        'brand' => 'Fender',
        'price' => 380,
        'location' => 'Bugis',
        'condition' => 'Like New',
        'category' => 'others',
        'image' => 'Electric_Guitar_Fender.jpeg',
        'description' => 'Fender electric guitar in like-new condition with excellent sound and a comfortable neck for playing.',
        'seller' => 'Andrew Tan',
        'views' => 39,
        'verified' => false,
    ],

];

// Fixtures have not been screened by the backend and must never display an
// approved/low-risk badge merely because the API is unavailable.
foreach ( $products as &$loopbuy_fixture_product ) {
	$loopbuy_fixture_product['verified']          = false;
	$loopbuy_fixture_product['safety_state']      = 'unavailable';
	$loopbuy_fixture_product['moderation_status'] = '';
	$loopbuy_fixture_product['scam_label']        = '';
}
unset( $loopbuy_fixture_product );

// A caller performing a failure-only fallback can suppress the catalogue
// request so these bundled fixtures remain the actual fallback data.
if ( empty( $loopbuy_skip_backend_catalog ) && function_exists( 'loopbuy_backend_get_public_products' ) ) {
	$loopbuy_api_products = loopbuy_backend_get_public_products();

	// A successful empty response is authoritative. Only transport or contract
	// failures return WP_Error and retain the bundled fixtures above.
	if ( ! is_wp_error( $loopbuy_api_products ) ) {
		$products = $loopbuy_api_products;
	}

	unset( $loopbuy_api_products );
}
