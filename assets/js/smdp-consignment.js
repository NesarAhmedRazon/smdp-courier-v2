/**
 * assets/js/smdp-consignment.js
 *
 * Cascading City → Zone → Area dropdowns on the WooCommerce order edit
 * screen. Talks to the wp_ajax_get_locations handler in
 * includes/location-picker.php, which is transient-cached server-side.
 *
 * v2.0.0: dropped the "provider" field that used to be sent with every
 * request — this plugin only ever queries Pathao, so it was always a
 * no-op parameter.
 */
jQuery(document).ready(function ($) {
  const orderId = $("#consignment_city").data("order-id");

  function showLoading(level) {
    $("#" + level + "-loading").show();
  }

  function hideLoading(level) {
    $("#" + level + "-loading").hide();
  }

  function showError(level, message) {
    console.error("Error [" + level + "]:", message);
  }

  /**
   * Populate a <select> with { sys_id, label } options.
   */
  function populateSelect($select, items, currentValue, valueKey, labelKey) {
    const level = $select.data("level");
    $select.html(
      '<option value="">Select ' +
        level.charAt(0).toUpperCase() +
        level.slice(1) +
        "</option>"
    );

    if (items && items.length) {
      items.forEach((item) => {
        const isSelected = currentValue && currentValue == item[valueKey];
        $select.append(
          new Option(item[labelKey], item[valueKey], false, isSelected)
        );
      });
    } else {
      $select.append(
        new Option("No " + level + " found", "", true, true)
      );
    }
    $select.prop("disabled", false);
  }

  /**
   * Fetch a locations list (city/zone/area) via AJAX.
   */
  function fetchLocations(level, parentId, $select, currentValue) {
    if (!parentId && level !== "city") return;

    showLoading(level);
    $select.prop("disabled", true);

    const data = {
      action: "get_locations",
      order_id: orderId,
      find: level,
      nonce: smdp_admin.nonce_pathao_locations,
    };

    if (parentId) {
      data.parent = parentId;
    }

    $.ajax({
      url: smdp_admin.ajaxurl,
      type: "POST",
      data: data,
      success: function (response) {
        hideLoading(level);
        if (response.success && response.data.length) {
          populateSelect($select, response.data, currentValue, "sys_id", "label");
        } else {
          showError(level, "No locations found");
          populateSelect($select, [], currentValue, "sys_id", "label");
        }
      },
      error: function (err) {
        hideLoading(level);
        showError(level, err);
        populateSelect($select, [], currentValue, "sys_id", "label");
      },
    });
  }

  // Fetch cities on load if none were pre-rendered server-side.
  const $citySelect = $("#consignment_city");
  const cityLoaded = parseInt($citySelect.data("loaded"));
  const currentCity = $citySelect.data("current");

  if (cityLoaded <= 0) {
    fetchLocations("city", null, $citySelect, currentCity);
  }

  $("#consignment_city").on("change", function () {
    const cityId = $(this).val();
    const $zoneSelect = $("#consignment_zone");
    const currentZone = $zoneSelect.data("current");

    $("#consignment_area").html('<option value="">Select Area</option>');

    if (!cityId) {
      $zoneSelect.html('<option value="">Select Zone</option>');
      return;
    }

    fetchLocations("zone", cityId, $zoneSelect, currentZone);
  });

  $("#consignment_zone").on("change", function () {
    const zoneId = $(this).val();
    const $areaSelect = $("#consignment_area");
    const currentArea = $areaSelect.data("current");

    if (!zoneId) {
      $areaSelect.html('<option value="">Select Area</option>');
      return;
    }

    fetchLocations("area", zoneId, $areaSelect, currentArea);
  });
});
