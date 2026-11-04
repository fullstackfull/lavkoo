$('.product-list-filter-input').on('change', function () {
    const inputName = $(this).attr('name');
    const inputValue = $(this).val();
    // Built from the full current URL, not origin+pathname: rebuilding from the path
    // dropped every other active filter, so changing the sort on a filtered listing
    // silently widened the results back out (brand page category chips, offer_type,
    // data_from, price...). Only the changed key is replaced.
    const newUrl = new URL(window.location.href);
    if (inputValue) {
        newUrl.searchParams.set(inputName, inputValue);
    } else {
        newUrl.searchParams.delete(inputName);
    }
    // A narrower list has fewer pages; staying on the old page number lands on an
    // empty one.
    newUrl.searchParams.delete('page');
    window.location.href = newUrl.toString();
});
$("#search-brand").on("keyup", function () {
    let value = this.value.toLowerCase().trim();
    $("#lista1 div>li").show().filter(function () {
        return $(this).text().toLowerCase().trim().indexOf(value) == -1;
    }).hide();
});

$(".search-product-attribute").on("keyup", function () {
    let value = this.value.toLowerCase().trim();
    let container = $(this).closest('.search-product-attribute-container');
    let listItems = container.find(".attribute-list ul>li");
    let noDataText = container.find(".no-data-found");

    $(this).closest('.search-product-attribute-container').find(".attribute-list ul>li").show().filter(function () {
        return $(this).text().toLowerCase().trim().indexOf(value) == -1;
    }).hide();

    if (listItems.filter(":visible").length === 0) {
        noDataText.show();
    } else {
        noDataText.hide();
    }
});
