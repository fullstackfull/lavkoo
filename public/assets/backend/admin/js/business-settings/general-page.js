"use strict";

async function googleMapInitialize() {
    let latitude = parseFloat($("#get-default-latitude").data('latitude')) || -33.8688;
    let longitude = parseFloat($("#get-default-longitude").data('longitude')) || 151.2195;
    let myLatLng = { lat: latitude, lng: longitude };

    const { Map } = await google.maps.importLibrary("maps");
    const { AdvancedMarkerElement } = await google.maps.importLibrary("marker");

    // Initialize Map
    const map = new Map(document.getElementById("location-map-canvas"), {
        center: myLatLng,
        zoom: 13,
        mapId: "roadmap",
    });

    // Store markers in an array to clear them later
    let markers = [];

    // Add initial marker
    let marker = new AdvancedMarkerElement({
        position: myLatLng,
        map: map,
    });
    markers.push(marker);

    let geocoder = new google.maps.Geocoder();

    // Click Event: Reset all markers and add new one
    google.maps.event.addListener(map, 'click', function (event) {
        let coordinates = event.latLng.toJSON();
        let latlng = new google.maps.LatLng(coordinates.lat, coordinates.lng);

        // Remove all existing markers
        markers.forEach(marker => {
            marker.setMap(null);
        });
        markers = [];

        // Add a new marker at the clicked location
        marker = new AdvancedMarkerElement({
            position: latlng,
            map: map,
        });

        markers.push(marker);
        map.panTo(latlng);

        // Update latitude and longitude inputs
        try {
            document.getElementById('latitude').value = coordinates.lat;
            document.getElementById('longitude').value = coordinates.lng;
            $("#get-default-latitude").html(coordinates.lat);
            $("#get-default-longitude").html(coordinates.lng);
        } catch (e) {}

        // Geocode to get the address from the clicked coordinates
        geocoder.geocode({ 'latLng': latlng }, function (results, status) {
            if (status === google.maps.GeocoderStatus.OK && results[1]) {
                const address = results[1].formatted_address;

                try {
                    document.getElementById('shop-address').value = address;
                } catch (e) {}

                try {
                    const mapInput = document.getElementById('map-pac-input');
                    if (mapInput) {
                        mapInput.value = address;
                    }
                } catch (e) {}
            }
        });
    });

    // Search Box Logic
    const input = document.getElementById("map-pac-input");
    if (input) {
        const searchBox = new google.maps.places.SearchBox(input);
        map.controls[google.maps.ControlPosition.TOP_CENTER].push(input);

        map.addListener("bounds_changed", () => {
            searchBox.setBounds(map.getBounds());
        });

        // Search result markers
        searchBox.addListener("places_changed", () => {
            const places = searchBox.getPlaces();
            if (places.length === 0) return;

            // Remove all markers when a new search is made
            markers.forEach(marker => {
                marker.setMap(null);
            });
            markers = [];

            const bounds = new google.maps.LatLngBounds();

            places.forEach((place) => {
                if (!place.geometry || !place.geometry.location) return;

                // Create a new marker for each place
                let newMarker = new AdvancedMarkerElement({
                    map,
                    title: place.name,
                    position: place.geometry.location,
                });

                google.maps.event.addListener(newMarker, "click", function () {
                    try {
                        document.getElementById('latitude').value = this.position.lat();
                        document.getElementById('longitude').value = this.position.lng();
                        $("#get-default-latitude").html(this.position.lat());
                        $("#get-default-longitude").html(this.position.lng());

                        const mapInput = document.getElementById('map-pac-input');
                        if (mapInput) {
                            mapInput.value = place.formatted_address || place.name;
                        }
                    } catch (e) {}
                });

                markers.push(newMarker);

                if (place.geometry.viewport) {
                    bounds.union(place.geometry.viewport);
                } else {
                    bounds.extend(place.geometry.location);
                }
            });

            map.fitBounds(bounds);

            if (places[1]) {
                try {
                    const mapInput = document.getElementById('map-pac-input');
                    if (mapInput) {
                        mapInput.value = places[1].formatted_address || places[1].name;
                    }
                } catch (e) {}
            }
        });
    }
}

$(document).on('ready', function () {
    try {
        googleMapInitialize();
    } catch (e) {
        console.warn('Map initialization error:', e);
    }
    try {
        toggleBusinessModelOption();
    } catch (e) {}
});

function toggleBusinessModelOption() {
    if ($('#business-mode-multi-vendor').prop('checked')) {
        $('.business-mode-multi-vendor-commission').slideDown().removeClass('d-none');
    } else {
        $('.business-mode-multi-vendor-commission').slideUp();
    }
}

$('#business-mode-multi-vendor, #business-mode-single-vendor').on('change', toggleBusinessModelOption);
