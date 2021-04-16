<script>
    !function () {
        var e = document.querySelectorAll("img[src*='{$images_api_url}snippet.svg'], img[src*='{$images_api_url}api/banner'], img[src*='{$images_api_url}LatitudePayPlusSnippet.svg']");
        [].forEach.call(
            e, function (e) {
                e.style.cursor = "pointer",
                    e.style.maxWidth = '100%',
                    e.addEventListener("click", handleClick)
            })
        function handleClick(e) {
            if (0 == document.getElementsByClassName("lpay-modal-wrapper").length) {
                var t = new XMLHttpRequest;
                t.onreadystatechange = function () {
                    4 == t.readyState && 200 == t.status && null != t.responseText && (document.body.insertAdjacentHTML("beforeend", t.responseText),
                        document.querySelector(".lpay-modal-wrapper").style.display = "block")
                },
                    t.open("GET", "{$images_api_url}modal.html", !0),
                    t.send(null)
            } else document.querySelector(".lpay-modal-wrapper").style.display = "block"
        }
    }();
</script>