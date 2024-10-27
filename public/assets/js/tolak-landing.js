(function ($) {
    "use strict";

    if ($(".dynamic-year").length) {
        let currentYear = new Date().getFullYear();
        $(".dynamic-year").html(currentYear);
    }

    if ($(".wow").length) {
        var wow = new WOW({
            boxClass: "wow", // animated element css class (default is wow)
            animateClass: "animated", // animation css class (default is animated)
            mobile: true, // trigger animations on mobile devices (default is true)
            live: true, // act on asynchronously loaded content (default is true)
        });
        wow.init();
    }

    if ($(".video-popup").length) {
        $(".video-popup").magnificPopup({
            type: "iframe",
            mainClass: "mfp-fade",
            removalDelay: 160,
            preloader: true,

            fixedContentPos: false
        });
    }


    var bounce = $("a.browse");
    if (bounce) {
        bounce.on("click", function (e) {
            e.preventDefault();
            $("html,body").animate({
                scrollTop: $("#demos").offset().top + 'px'
            }, 1000);
        });
    }
    var bounce = $("a.features");
    if (bounce) {
        bounce.on("click", function (e) {
            e.preventDefault();
            $("html,body").animate({
                scrollTop: $("#features").offset().top + 'px'
            }, 1000);
        });
    }

    //Fact Counter + Text Count
    if ($(".count-box").length) {
        $(".count-box").appear(
            function () {
                var $t = $(this),
                    n = $t.find(".count-text").attr("data-stop"),
                    r = parseInt($t.find(".count-text").attr("data-speed"), 10);

                if (!$t.hasClass("counted")) {
                    $t.addClass("counted");
                    $({
                        countNum: $t.find(".count-text").text()
                    }).animate({
                        countNum: n
                    }, {
                        duration: r,
                        easing: "linear",
                        step: function () {
                            $t.find(".count-text").text(Math.floor(this.countNum));
                        },
                        complete: function () {
                            $t.find(".count-text").text(this.countNum);
                        }
                    });
                }
            }, {
                accY: 0
            }
        );
    }
    /*-- Hover Tilt --*/
    let tolakTiltElm = $(".tolak-tilt");
    if (tolakTiltElm.length) {
        tolakTiltElm.each(function () {
            let self = $(this);
            let options = self.data("tilt-options");
            let tolakTilt = self.tilt(
                "object" === typeof options ? options : JSON.parse(options)
            );
        });
    }

    function thmOwlInit() {
        // owl slider
        let tolakowlCarousel = $(".tolak-owl__carousel");
        if (tolakowlCarousel.length) {
            tolakowlCarousel.each(function () {
                let elm = $(this);
                let options = elm.data("owl-options");
                let thmOwlCarousel = elm.owlCarousel(
                    "object" === typeof options ? options : JSON.parse(options)
                );
                elm.find("button").each(function () {
                    $(this).attr("aria-label", "carousel button");
                });
            });
        }
    }
    $(window).on("load", function () {
        thmOwlInit();
    });

})(jQuery);