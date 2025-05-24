<div class="search-card">
    <h2>Find out your product’s price history</h2>
    <form class="search-form">
        <input type="text" class="search-input" placeholder="Paste the product link">
        <button type="submit" class="search-button"><i class="fas fa-magnifying-glass"></i> Search</button>
    </form>
    <div class="search-merchants">
        Supported Merchants:
        <img src="/assets/images/logos/amazon.svg" alt="Amazon">
        <img src="/assets/images/logos/flipkart.svg" alt="Flipkart">
    </div>
</div>
<div id="search-preview-popup" class="popup" style="display: none;">
    <i class="fas fa-times popup-close" onclick="hidePopup('search-preview-popup')"></i>
    <div class="popup-content"></div>
</div>
<div id="search-error-popup" class="popup" style="display: none;">
    <i class="fas fa-times popup-close" onclick="hidePopup('search-error-popup')"></i>
    <div class="popup-content"></div>
</div>