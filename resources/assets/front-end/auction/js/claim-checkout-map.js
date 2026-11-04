"use strict";

var _auctionBillingMapInitialized = false;
var _auctionBillingMapRef = null;

function _auctionGetDefaultLat() {
    var el = document.getElementById('auction-default-lat');
    var val = el ? parseFloat(el.dataset.value) : NaN;
    return isNaN(val) ? -33.8688 : val;
}

function _auctionGetDefaultLng() {
    var el = document.getElementById('auction-default-lng');
    var val = el ? parseFloat(el.dataset.value) : NaN;
    return isNaN(val) ? 151.2195 : val;
}

async function _auctionInitShippingMap() {
    var canvas = document.getElementById('auction-shipping-map-canvas');
    if (!canvas) return;

    var lat = _auctionGetDefaultLat();
    var lng = _auctionGetDefaultLng();
    var center = { lat: lat, lng: lng };

    var mapsLib   = await google.maps.importLibrary("maps");
    var markerLib = await google.maps.importLibrary("marker");
    var Map                 = mapsLib.Map;
    var AdvancedMarkerElement = markerLib.AdvancedMarkerElement;

    var map = new Map(canvas, { center: center, zoom: 13, mapId: "roadmap" });
    var marker = new AdvancedMarkerElement({ position: center, map: map });

    var geocoder = new google.maps.Geocoder();

    google.maps.event.addListener(map, 'click', function (evt) {
        var coords = evt.latLng.toJSON();
        marker.position = coords;
        map.panTo(evt.latLng);

        var latEl = document.getElementById('auction-shipping-lat');
        var lngEl = document.getElementById('auction-shipping-lng');
        if (latEl) latEl.value = coords.lat;
        if (lngEl) lngEl.value = coords.lng;

        geocoder.geocode({ latLng: evt.latLng }, function (results, status) {
            if (status === google.maps.GeocoderStatus.OK && results[1]) {
                var field = document.querySelector('[name="shipping_address_info[address]"]');
                if (field) field.value = results[1].formatted_address;
            }
        });
    });

    var searchInput = document.getElementById('auction-pac-input');
    if (searchInput) {
        var searchBox = new google.maps.places.SearchBox(searchInput);
        map.controls[google.maps.ControlPosition.TOP_CENTER].push(searchInput);
        map.addListener('bounds_changed', function () {
            searchBox.setBounds(map.getBounds());
        });
        searchBox.addListener('places_changed', function () {
            var places = searchBox.getPlaces();
            if (!places || !places.length) return;
            var bounds = new google.maps.LatLngBounds();
            places.forEach(function (place) {
                if (!place.geometry || !place.geometry.location) return;
                marker.position = place.geometry.location;
                var latEl = document.getElementById('auction-shipping-lat');
                var lngEl = document.getElementById('auction-shipping-lng');
                if (latEl) latEl.value = place.geometry.location.lat();
                if (lngEl) lngEl.value = place.geometry.location.lng();
                if (place.geometry.viewport) {
                    bounds.union(place.geometry.viewport);
                } else {
                    bounds.extend(place.geometry.location);
                }
            });
            map.fitBounds(bounds);
        });
    }
}

async function _auctionInitBillingMap() {
    if (_auctionBillingMapInitialized) return;
    var canvas = document.getElementById('auction-billing-map-canvas');
    if (!canvas) return;
    _auctionBillingMapInitialized = true;

    var lat = _auctionGetDefaultLat();
    var lng = _auctionGetDefaultLng();
    var center = { lat: lat, lng: lng };

    var mapsLib   = await google.maps.importLibrary("maps");
    var markerLib = await google.maps.importLibrary("marker");
    var Map                 = mapsLib.Map;
    var AdvancedMarkerElement = markerLib.AdvancedMarkerElement;

    var map = new Map(canvas, { center: center, zoom: 13, mapId: "roadmap" });
    _auctionBillingMapRef = map;
    var marker = new AdvancedMarkerElement({ position: center, map: map });

    var geocoder = new google.maps.Geocoder();

    google.maps.event.addListener(map, 'click', function (evt) {
        var coords = evt.latLng.toJSON();
        marker.position = coords;
        map.panTo(evt.latLng);

        var latEl = document.getElementById('auction-billing-lat');
        var lngEl = document.getElementById('auction-billing-lng');
        if (latEl) latEl.value = coords.lat;
        if (lngEl) lngEl.value = coords.lng;

        geocoder.geocode({ latLng: evt.latLng }, function (results, status) {
            if (status === google.maps.GeocoderStatus.OK && results[1]) {
                var field = document.querySelector('[name="billing_address_info[address]"]');
                if (field) field.value = results[1].formatted_address;
            }
        });
    });

    var searchInput = document.getElementById('auction-pac-input-billing');
    if (searchInput) {
        var searchBox = new google.maps.places.SearchBox(searchInput);
        map.controls[google.maps.ControlPosition.TOP_CENTER].push(searchInput);
        map.addListener('bounds_changed', function () {
            searchBox.setBounds(map.getBounds());
        });
        searchBox.addListener('places_changed', function () {
            var places = searchBox.getPlaces();
            if (!places || !places.length) return;
            var bounds = new google.maps.LatLngBounds();
            places.forEach(function (place) {
                if (!place.geometry || !place.geometry.location) return;
                marker.position = place.geometry.location;
                var latEl = document.getElementById('auction-billing-lat');
                var lngEl = document.getElementById('auction-billing-lng');
                if (latEl) latEl.value = place.geometry.location.lat();
                if (lngEl) lngEl.value = place.geometry.location.lng();
                if (place.geometry.viewport) {
                    bounds.union(place.geometry.viewport);
                } else {
                    bounds.extend(place.geometry.location);
                }
            });
            map.fitBounds(bounds);
        });
    }
}

function auctionMapsShopping() {
    try { _auctionInitShippingMap(); } catch (e) {}
    try { _auctionInitBillingMap(); } catch (e) {}

    var sameAsBilling = document.getElementById('sameAsBilling');
    if (sameAsBilling) {
        sameAsBilling.addEventListener('change', function () {
            if (!this.checked) {
                setTimeout(function () {
                    if (_auctionBillingMapRef) {
                        try { google.maps.event.trigger(_auctionBillingMapRef, 'resize'); } catch (e) {}
                    } else {
                        try { _auctionInitBillingMap(); } catch (e) {}
                    }
                }, 50);
            }
        });
    }
}
