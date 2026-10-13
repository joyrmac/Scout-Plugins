/* Scout Call Buttons settings: pick up to three photos from the media library. */
(function () {
  var pick = document.getElementById('scc-pick'), clear = document.getElementById('scc-clear');
  var field = document.getElementById('scc-photos'), list = document.getElementById('scc-photo-list');
  if (!pick || !field || !window.wp || !wp.media) return;
  var frame;
  pick.addEventListener('click', function () {
    if (!frame) {
      frame = wp.media({ title: 'Choose up to three photos', multiple: true, library: { type: 'image' } });
      frame.on('select', function () {
        var picked = frame.state().get('selection').toJSON().slice(0, 3);
        field.value = picked.map(function (a) { return a.id; }).join(',');
        list.innerHTML = '';
        picked.forEach(function (a) {
          var img = document.createElement('img');
          img.src = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url);
          img.alt = ''; img.style.cssText = 'width:48px;height:48px;border-radius:50%;object-fit:cover';
          list.appendChild(img);
        });
      });
    }
    frame.open();
  });
  if (clear) clear.addEventListener('click', function () { field.value = ''; list.innerHTML = ''; });
})();
