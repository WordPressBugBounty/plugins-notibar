(function ($) {
  // Guard against redefinition if this module is bundled by multiple consumer
  // plugins on the same admin page (registry arbitration only picks one PHP
  // winner, but each consumer may still enqueue its own copy of this script).
  if (window.njtAdsToggleRender) {
    return;
  }

  /**
   * Render an ads on/off toggle into `container`. Default layout ("switch", the default style):
   * pill switch on the left, text stack on the right (bold title, muted description below).
   * With options.style = "checkbox": stacked layout — bold title, then a square checkbox,
   * then description below (same markup, a modifier class switches the CSS presentation).
   *
   * @param {string|Element|jQuery} container Where to render the toggle.
   * @param {Object} options
   * @param {string} options.consumerSlug This consumer plugin's own registered slug (e.g.
   *   "whatsapp-wp") — must match a slug it registered via
   *   \YayRecommendedModules\Registry::register_ads_consumer(). One switch controls EVERY ad
   *   module that consumer registered together — a different consumer bundling the same ad is
   *   unaffected by this switch.
   * @param {string} [options.title] Bold heading (e.g. "Show ads").
   * @param {string} [options.description] Smaller, muted text shown below the title.
   * @param {boolean} [options.checked] Initial state (caller should pass the
   *   current njt_ads_toggle_consumer_is_enabled( consumerSlug ) value so the UI starts in sync).
   * @param {string} [options.style] "switch" (default) or "checkbox".
   */
  window.njtAdsToggleRender = function (container, options) {
    var settings = options || {};
    var $container = $(container);
    var rowClass =
      "yay-ads-toggle-row" +
      (settings.style === "checkbox" ? " yay-ads-toggle-row--checkbox" : "");

    var $wrap = $(
      '<div class="' + rowClass + '">' +
        '<label class="yay-ads-toggle-switch">' +
          '<input type="checkbox" class="yay-ads-toggle-input" />' +
          '<span class="yay-ads-toggle-slider"></span>' +
        "</label>" +
        '<div class="yay-ads-toggle-text">' +
          '<div class="yay-ads-toggle-title"></div>' +
          '<div class="yay-ads-toggle-description"></div>' +
        "</div>" +
        "</div>",
    );

    $wrap
      .find(".yay-ads-toggle-input")
      .prop("checked", !!settings.checked);

    $wrap.find(".yay-ads-toggle-title").text(settings.title || "");
    $wrap.find(".yay-ads-toggle-description").text(settings.description || "");

    $wrap.find(".yay-ads-toggle-input").on("change", function () {
      var $input = $(this);
      var $switch = $input.closest(".yay-ads-toggle-switch");
      var enabled = $input.is(":checked");

      $input.prop("disabled", true);
      $switch.addClass("is-loading");

      $.ajax({
        url: ajaxurl,
        method: "POST",
        data: {
          action: "njt_ads_toggle_update",
          nonce: NjtAdsToggle.nonce,
          consumer_slug: settings.consumerSlug,
          enabled: enabled ? "1" : "0",
        },
        success: function (response) {
          if (!response.success) {
            $input.prop("checked", !enabled); // revert on rejection (e.g. unknown consumer)
          }
        },
        error: function () {
          $input.prop("checked", !enabled); // revert on request failure
        },
        complete: function () {
          $input.prop("disabled", false);
          $switch.removeClass("is-loading");
        },
      });
    });

    $container.empty().append($wrap);
  };
})(jQuery);
