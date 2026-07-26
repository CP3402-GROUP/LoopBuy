<?php
/**
 * The main template file
 *
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query.
 * E.g., it puts together the home page when no home.php file exists.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package LoopBuy
 */
get_header();

/* =========================================================
   PRODUCT DATA
   Later database/backend can replace this array.
========================================================= */

$products = [

    /* =========================
       ELECTRONICS
    ========================= */

    [
        'name' => 'iPhone 14 Pro',
        'brand' => 'Apple',
        'price' => 750,
        'location' => 'Orchard',
        'condition' => 'Like New',
        'category' => 'electronics',
        'image' => 'image1.jpg'
    ],

    [
        'name' => 'Wireless Headphones',
        'brand' => 'Sony',
        'price' => 120,
        'location' => 'Bishan',
        'condition' => 'Good',
        'category' => 'electronics',
        'image' => 'image2.jpg'
    ],

    [
        'name' => 'Samsung Galaxy S23',
        'brand' => 'Samsung',
        'price' => 650,
        'location' => 'Tampines',
        'condition' => 'Like New',
        'category' => 'electronics',
        'image' => 'image3.jpg'
    ],

    [
        'name' => 'MacBook Air M2',
        'brand' => 'Apple',
        'price' => 980,
        'location' => 'Jurong East',
        'condition' => 'Good',
        'category' => 'electronics',
        'image' => 'image4.jpg'
    ],


    /* =========================
       FASHION
    ========================= */

    [
        'name' => 'Leather Jacket',
        'brand' => 'Zara',
        'price' => 85,
        'location' => 'Tampines',
        'condition' => 'Like New',
        'category' => 'fashion',
        'image' => 'image5.jpg'
    ],

    [
        'name' => 'Nike Running Shoes',
        'brand' => 'Nike',
        'price' => 95,
        'location' => 'Jurong',
        'condition' => 'Good',
        'category' => 'fashion',
        'image' => 'image6.jpg'
    ],

    [
        'name' => 'Denim Jacket',
        'brand' => 'Levi\'s',
        'price' => 60,
        'location' => 'Bugis',
        'condition' => 'Good',
        'category' => 'fashion',
        'image' => 'image7.jpg'
    ],

    [
        'name' => 'Classic Handbag',
        'brand' => 'Charles & Keith',
        'price' => 55,
        'location' => 'Woodlands',
        'condition' => 'Like New',
        'category' => 'fashion',
        'image' => 'image8.jpg'
    ],


    /* =========================
       GAMING
    ========================= */

    [
        'name' => 'PlayStation 5',
        'brand' => 'Sony',
        'price' => 480,
        'location' => 'Punggol',
        'condition' => 'Like New',
        'category' => 'gaming',
        'image' => 'image9.jpg'
    ],

    [
        'name' => 'Nintendo Switch OLED',
        'brand' => 'Nintendo',
        'price' => 320,
        'location' => 'Serangoon',
        'condition' => 'Good',
        'category' => 'gaming',
        'image' => 'image10.jpg'
    ],

    [
        'name' => 'Xbox Series S',
        'brand' => 'Microsoft',
        'price' => 280,
        'location' => 'Hougang',
        'condition' => 'Good',
        'category' => 'gaming',
        'image' => 'image11.jpg'
    ],

    [
        'name' => 'Gaming Mechanical Keyboard',
        'brand' => 'Razer',
        'price' => 80,
        'location' => 'Clementi',
        'condition' => 'Like New',
        'category' => 'gaming',
        'image' => 'image12.jpg'
    ],


    /* =========================
       SPORTS
    ========================= */

    [
        'name' => 'Mountain Bike Trek',
        'brand' => 'Trek',
        'price' => 320,
        'location' => 'East Coast',
        'condition' => 'Like New',
        'category' => 'sports',
        'image' => 'image13.jpg'
    ],

    [
        'name' => 'Wilson Tennis Racket',
        'brand' => 'Wilson',
        'price' => 70,
        'location' => 'Bishan',
        'condition' => 'Good',
        'category' => 'sports',
        'image' => 'image14.jpg'
    ],

    [
        'name' => 'Adidas Football',
        'brand' => 'Adidas',
        'price' => 35,
        'location' => 'Yishun',
        'condition' => 'Good',
        'category' => 'sports',
        'image' => 'image15.jpg'
    ],

    [
        'name' => 'Yoga Mat Premium',
        'brand' => 'Manduka',
        'price' => 45,
        'location' => 'Novena',
        'condition' => 'Like New',
        'category' => 'sports',
        'image' => 'image16.jpg'
    ],


    /* =========================
       HOME APPLIANCES
    ========================= */

    [
        'name' => 'Air Fryer',
        'brand' => 'Philips',
        'price' => 75,
        'location' => 'Ang Mo Kio',
        'condition' => 'Good',
        'category' => 'home-appliances',
        'image' => 'image17.jpg'
    ],

    [
        'name' => 'Rice Cooker',
        'brand' => 'Panasonic',
        'price' => 50,
        'location' => 'Toa Payoh',
        'condition' => 'Good',
        'category' => 'home-appliances',
        'image' => 'image18.jpg'
    ],

    [
        'name' => 'Vacuum Cleaner',
        'brand' => 'Dyson',
        'price' => 260,
        'location' => 'Bedok',
        'condition' => 'Like New',
        'category' => 'home-appliances',
        'image' => 'image19.jpg'
    ],

    [
        'name' => 'Coffee Machine',
        'brand' => 'Nespresso',
        'price' => 110,
        'location' => 'Queenstown',
        'condition' => 'Like New',
        'category' => 'home-appliances',
        'image' => 'image20.jpg'
    ],


    /* =========================
       BOOKS
    ========================= */

    [
        'name' => 'Harry Potter Book Set',
        'brand' => 'Bloomsbury',
        'price' => 45,
        'location' => 'Bukit Timah',
        'condition' => 'Good',
        'category' => 'books',
        'image' => 'image21.jpg'
    ],

    [
        'name' => 'Atomic Habits',
        'brand' => 'James Clear',
        'price' => 12,
        'location' => 'Clementi',
        'condition' => 'Like New',
        'category' => 'books',
        'image' => 'image22.jpg'
    ],

    [
        'name' => 'Python Programming Book',
        'brand' => 'O\'Reilly',
        'price' => 25,
        'location' => 'Jurong East',
        'condition' => 'Good',
        'category' => 'books',
        'image' => 'image23.jpg'
    ],

    [
        'name' => 'The Psychology of Money',
        'brand' => 'Morgan Housel',
        'price' => 15,
        'location' => 'Orchard',
        'condition' => 'Like New',
        'category' => 'books',
        'image' => 'image24.jpg'
    ],


    /* =========================
       FURNITURE
    ========================= */

    [
        'name' => 'Wooden Study Desk',
        'brand' => 'IKEA',
        'price' => 90,
        'location' => 'Woodlands',
        'condition' => 'Good',
        'category' => 'furniture',
        'image' => 'image25.jpg'
    ],

    [
        'name' => 'Office Chair',
        'brand' => 'IKEA',
        'price' => 75,
        'location' => 'Tampines',
        'condition' => 'Good',
        'category' => 'furniture',
        'image' => 'image26.jpg'
    ],

    [
        'name' => 'Three Seater Sofa',
        'brand' => 'Castlery',
        'price' => 350,
        'location' => 'Sengkang',
        'condition' => 'Like New',
        'category' => 'furniture',
        'image' => 'image27.jpg'
    ],

    [
        'name' => 'Bedside Table',
        'brand' => 'Muji',
        'price' => 50,
        'location' => 'Pasir Ris',
        'condition' => 'Good',
        'category' => 'furniture',
        'image' => 'image28.jpg'
    ],


    /* =========================
       OTHERS
    ========================= */

    [
        'name' => 'Acoustic Guitar',
        'brand' => 'Yamaha',
        'price' => 140,
        'location' => 'Bishan',
        'condition' => 'Good',
        'category' => 'others',
        'image' => 'image29.jpg'
    ],

    [
        'name' => 'Digital Camera',
        'brand' => 'Canon',
        'price' => 290,
        'location' => 'Orchard',
        'condition' => 'Like New',
        'category' => 'others',
        'image' => 'image30.jpg'
    ],

    [
        'name' => 'Travel Suitcase',
        'brand' => 'Samsonite',
        'price' => 80,
        'location' => 'Changi',
        'condition' => 'Good',
        'category' => 'others',
        'image' => 'image31.jpg'
    ],

    [
        'name' => 'Electric Guitar',
        'brand' => 'Fender',
        'price' => 380,
        'location' => 'Bugis',
        'condition' => 'Like New',
        'category' => 'others',
        'image' => 'image32.jpg'
    ]

];


/* =========================================================
   GET SELECTED CATEGORY
========================================================= */

$selected_category = isset($_GET['category'])
    ? sanitize_text_field($_GET['category'])
    : 'all';


/* =========================================================
   GET SEARCH TEXT
========================================================= */

$search_text = isset($_GET['product_search'])
    ? sanitize_text_field($_GET['product_search'])
    : '';


/* =========================================================
   FILTER VALUES
========================================================= */

$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== ''
    ? (float) $_GET['min_price']
    : null;

$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== ''
    ? (float) $_GET['max_price']
    : null;

$condition_filter = isset($_GET['condition'])
    ? sanitize_text_field($_GET['condition'])
    : '';

$location_filter = isset($_GET['location'])
    ? sanitize_text_field($_GET['location'])
    : '';

/* =========================================================
   FILTER PRODUCTS
========================================================= */

$filtered_products = array_filter(
    $products,
    function ($product) use (
        $selected_category,
        $search_text,
        $min_price,
        $max_price,
        $condition_filter,
        $location_filter
    ) {

        /* -------------------------
           CATEGORY
        ------------------------- */

        $category_match =
            $selected_category === 'all'
            || $product['category'] === $selected_category;


        /* -------------------------
           SEARCH
        ------------------------- */

        $search_match = true;

        if (!empty($search_text)) {

            $search_match =
                stripos($product['name'], $search_text) !== false
                || stripos($product['brand'], $search_text) !== false
                || stripos($product['category'], $search_text) !== false;

        }


        /* -------------------------
           MIN PRICE
        ------------------------- */

        $min_price_match = true;

        if ($min_price !== null) {
            $min_price_match = $product['price'] >= $min_price;
        }


        /* -------------------------
           MAX PRICE
        ------------------------- */

        $max_price_match = true;

        if ($max_price !== null) {
            $max_price_match = $product['price'] <= $max_price;
        }


        /* -------------------------
           CONDITION
        ------------------------- */

        $condition_match = true;

        if (!empty($condition_filter)) {

            $condition_match =
                $product['condition'] === $condition_filter;

        }


        /* -------------------------
           LOCATION
        ------------------------- */

        $location_match = true;

        if (!empty($location_filter)) {

            $location_match =
                stripos(
                    $product['location'],
                    $location_filter
                ) !== false;

        }


        /* -------------------------
           FINAL RESULT
        ------------------------- */

        return
            $category_match
            && $search_match
            && $min_price_match
            && $max_price_match
            && $condition_match
            && $location_match;

    }
);


/* =========================================================
   SORT PRODUCTS
========================================================= */

$sort = isset($_GET['sort'])
    ? sanitize_text_field($_GET['sort'])
    : 'newest';


if ($sort === 'price-low') {

    usort(
        $filtered_products,
        function ($a, $b) {
            return $a['price'] <=> $b['price'];
        }
    );

}

elseif ($sort === 'price-high') {

    usort(
        $filtered_products,
        function ($a, $b) {
            return $b['price'] <=> $a['price'];
        }
    );

}

?>


<main id="primary" class="site-main loopbuy-home">


    <!-- =====================================================
         HERO SECTION
    ====================================================== -->

    <section class="loopbuy-hero">

        <div class="hero-content">

            <h1>
                Second-hand finds, safer trades.
            </h1>

            <p>
                Browse pre-loved items near you.
                Find great deals and give items a second life.
            </p>


            <form
                class="hero-search"
                action="<?php echo esc_url(home_url('/')); ?>"
                method="get"
            >

                <input
                    type="search"
                    name="product_search"
                    value="<?php echo esc_attr($search_text); ?>"
                    placeholder="Try 'iPhone', 'bicycle'..."
                >

                <button type="submit">
                    Search
                </button>

            </form>

        </div>

    </section>



    <!-- =====================================================
         CATEGORY SECTION
    ====================================================== -->

    <section class="category-section">

        <div class="category-pills">


            <!-- ALL -->

            <a
                href="<?php echo esc_url(home_url('/?category=all')); ?>"
                class="category-pill <?php echo $selected_category === 'all' ? 'active' : ''; ?>"
            >
                All
            </a>


            <!-- GAMING -->

            <a
                href="<?php echo esc_url(home_url('/?category=gaming')); ?>"
                class="category-pill <?php echo $selected_category === 'gaming' ? 'active' : ''; ?>"
            >
                🎮 Gaming
            </a>


            <!-- FASHION -->

            <a
                href="<?php echo esc_url(home_url('/?category=fashion')); ?>"
                class="category-pill <?php echo $selected_category === 'fashion' ? 'active' : ''; ?>"
            >
                👕 Fashion
            </a>


            <!-- OTHERS -->

            <a
                href="<?php echo esc_url(home_url('/?category=others')); ?>"
                class="category-pill <?php echo $selected_category === 'others' ? 'active' : ''; ?>"
            >
                📦 Others
            </a>


            <!-- SPORTS -->

            <a
                href="<?php echo esc_url(home_url('/?category=sports')); ?>"
                class="category-pill <?php echo $selected_category === 'sports' ? 'active' : ''; ?>"
            >
                ⚽ Sports
            </a>


            <!-- HOME APPLIANCES -->

            <a
                href="<?php echo esc_url(home_url('/?category=home-appliances')); ?>"
                class="category-pill <?php echo $selected_category === 'home-appliances' ? 'active' : ''; ?>"
            >
                🏠 Home Appliances
            </a>


            <!-- ELECTRONICS -->

            <a
                href="<?php echo esc_url(home_url('/?category=electronics')); ?>"
                class="category-pill <?php echo $selected_category === 'electronics' ? 'active' : ''; ?>"
            >
                📱 Electronics
            </a>


            <!-- BOOKS -->

            <a
                href="<?php echo esc_url(home_url('/?category=books')); ?>"
                class="category-pill <?php echo $selected_category === 'books' ? 'active' : ''; ?>"
            >
                📚 Books
            </a>


            <!-- FURNITURE -->

            <a
                href="<?php echo esc_url(home_url('/?category=furniture')); ?>"
                class="category-pill <?php echo $selected_category === 'furniture' ? 'active' : ''; ?>"
            >
                🛏 Furniture
            </a>


        </div>

    </section>



    <!-- =====================================================
         PRODUCT SECTION
    ====================================================== -->

    <section class="products-section">


        <!-- PRODUCT TOOLBAR -->

        <div class="products-toolbar">


            <div class="product-count">

                <?php echo count($filtered_products); ?> items

            </div>



            <div class="product-options">


                <button
                    class="filter-button"
                    type="button"
                >
                    ☷ Filters
                </button>


                <form method="get" class="sort-form">


                    <input
                        type="hidden"
                        name="category"
                        value="<?php echo esc_attr($selected_category); ?>"
                    >


                    <?php if (!empty($search_text)) : ?>

                        <input
                            type="hidden"
                            name="product_search"
                            value="<?php echo esc_attr($search_text); ?>"
                        >

                    <?php endif; ?>


                    <select
                        class="sort-select"
                        name="sort"
                        onchange="this.form.submit()"
                    >

                        <option
                            value="newest"
                            <?php selected($sort, 'newest'); ?>
                        >
                            Newest
                        </option>

                        <option
                            value="price-low"
                            <?php selected($sort, 'price-low'); ?>
                        >
                            Price: Low to High
                        </option>

                        <option
                            value="price-high"
                            <?php selected($sort, 'price-high'); ?>
                        >
                            Price: High to Low
                        </option>

                    </select>


                </form>

            </div>

        </div>

		<!-- ======================================
     FILTER PANEL
======================================= -->

<form class="product-filter-panel" method="get">

    <!-- Keep current category selected -->
    <input
        type="hidden"
        name="category"
        value="<?php echo esc_attr($selected_category); ?>"
    >

    <!-- Keep search text -->
    <?php if (!empty($search_text)) : ?>
        <input
            type="hidden"
            name="product_search"
            value="<?php echo esc_attr($search_text); ?>"
        >
    <?php endif; ?>


    <!-- MIN PRICE -->
    <div class="filter-field">

        <label for="min-price">
            Min Price
        </label>

        <input
            type="number"
            id="min-price"
            name="min_price"
            min="0"
            placeholder="0"
            value="<?php echo isset($_GET['min_price']) ? esc_attr($_GET['min_price']) : ''; ?>"
        >

    </div>


    <!-- MAX PRICE -->
    <div class="filter-field">

        <label for="max-price">
            Max Price
        </label>

        <input
            type="number"
            id="max-price"
            name="max_price"
            min="0"
            placeholder="Any"
            value="<?php echo isset($_GET['max_price']) ? esc_attr($_GET['max_price']) : ''; ?>"
        >

    </div>


    <!-- CONDITION -->
    <div class="filter-field">

        <label for="condition">
            Condition
        </label>

        <select
            id="condition"
            name="condition"
        >

            <option value="">
                Any
            </option>

            <option
                value="Like New"
                <?php selected(
                    isset($_GET['condition']) ? $_GET['condition'] : '',
                    'Like New'
                ); ?>
            >
                Like New
            </option>

            <option
                value="Good"
                <?php selected(
                    isset($_GET['condition']) ? $_GET['condition'] : '',
                    'Good'
                ); ?>
            >
                Good
            </option>

        </select>

    </div>


    <!-- LOCATION -->
    <div class="filter-field">

        <label for="location">
            Location
        </label>

        <input
            type="text"
            id="location"
            name="location"
            placeholder="e.g. Singapore"
            value="<?php echo isset($_GET['location']) ? esc_attr($_GET['location']) : ''; ?>"
        >

    </div>


    <!-- APPLY BUTTON -->
    <div class="filter-field filter-actions">

        <button
            type="submit"
            class="apply-filter-button"
        >
            Apply Filters
        </button>

        <a
            href="<?php echo esc_url(
                home_url('/?category=' . $selected_category)
            ); ?>"
            class="clear-filter-button"
        >
            Clear
        </a>

    </div>

</form>



        <!-- =================================================
             PRODUCT GRID
        ================================================== -->

        <div class="products-grid">


            <?php if (!empty($filtered_products)) : ?>


                <?php foreach ($filtered_products as $product) : ?>


                    <article class="product-card">


                        <!-- PRODUCT IMAGE -->

                        <div class="product-image">


                            <span
                                class="condition-badge
                                <?php
                                echo $product['condition'] === 'Good'
                                    ? 'condition-good'
                                    : '';
                                ?>"
                            >

                                <?php
                                echo esc_html(
                                    $product['condition']
                                );
                                ?>

                            </span>



                            <button
                                class="favourite-button"
                                type="button"
                                aria-label="Save product"
                            >
                                ♡
                            </button>



                            <img
                                src="<?php
                                echo esc_url(
                                    get_template_directory_uri()
                                    . '/images/'
                                    . $product['image']
                                );
                                ?>"
                                alt="<?php
                                echo esc_attr(
                                    $product['name']
                                );
                                ?>"
                            >


                        </div>



                        <!-- PRODUCT INFORMATION -->

                        <div class="product-content">


                            <h2>

                                <?php
                                echo esc_html(
                                    $product['name']
                                );
                                ?>

                            </h2>


                            <p class="product-brand">

                                <?php
                                echo esc_html(
                                    $product['brand']
                                );
                                ?>

                            </p>


                            <p class="product-price">

                                $<?php
                                echo number_format(
                                    $product['price']
                                );
                                ?>

                            </p>


                            <p class="product-location">

                                ⌖
                                <?php
                                echo esc_html(
                                    $product['location']
                                );
                                ?>

                            </p>


                        </div>


                    </article>


                <?php endforeach; ?>


            <?php else : ?>


                <div class="no-products">

                    <h2>
                        No products found
                    </h2>

                    <p>
                        Try another category or search term.
                    </p>

                </div>


            <?php endif; ?>


        </div>


    </section>


</main>


<script>
document.addEventListener('DOMContentLoaded', function () {

	const filterButton = document.querySelector('.filter-button');
	const filterPanel = document.querySelector('.product-filter-panel');

	if (filterButton && filterPanel) {

		filterButton.addEventListener('click', function () {

			filterPanel.classList.toggle('show');

		});

	}

});
</script>

<?php
get_footer();
?>

