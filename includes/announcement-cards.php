<?php
/**
 * Public announcement cards + full-announcement modal, shared by the landing
 * page and the Announcements page.
 *
 * Every card has the same structure and (via the grid + line clamping) the
 * same height: thumbnail (when there is one), category + pinned badges,
 * title, date, a short excerpt, and a "Read More" footer. The complete text
 * and posters only appear in the modal — the page never navigates away.
 * Each poster in the modal opens full-size in a lightbox.
 *
 * Expects $announcements (rows from `announcements`). Optional
 * $donorModalId: the id of a donor-list dialog on the page; the current
 * week's donor announcement then gets a "View donor list" button in its modal.
 */
$donorModalId = $donorModalId ?? null;

$annCards = [];
$annPayload = [];
foreach ($announcements as $a) {
    // Up to ANNOUNCEMENT_MAX_IMAGES posters; older rows may hold a PDF poster.
    $photos = [];
    $pdfs = [];
    foreach (announcementImageSlots($a) as $path) {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
            $pdfs[] = documentUrl($path);
        } else {
            $photos[] = documentUrl($path);
        }
    }
    $status = announcementStatus($a['start_date'] ?? null, $a['end_date'] ?? null);

    $period = '';
    if (!empty($a['start_date']) && !empty($a['end_date'])) {
        $period = $a['start_date'] === $a['end_date']
            ? formatDate($a['start_date'])
            : formatDate($a['start_date']) . ' – ' . formatDate($a['end_date']);
    } elseif (!empty($a['start_date'])) {
        $period = 'Starts ' . formatDate($a['start_date']);
    } elseif (!empty($a['end_date'])) {
        $period = 'Until ' . formatDate($a['end_date']);
    }

    $card = [
        'id' => (int) $a['announcement_id'],
        'title' => $a['title'],
        'category' => $a['category'] ?: 'Announcement',
        'pinned' => !empty($a['is_pinned']),
        'upcoming' => $status === 'Upcoming',
        'posted' => formatDate($a['created_at']),
        'period' => $period,
        'excerpt' => announcementExcerpt($a['content']),
        'images' => $photos,
        'pdfs' => $pdfs,
        'donor' => $donorModalId && isCurrentWeeklyDonorAnnouncement($a['title']),
    ];
    $annCards[] = $card;
    $annPayload[$card['id']] = $card + ['content' => $a['content']];
}
?>
<div class="ann-grid">
  <?php foreach ($annCards as $c): ?>
    <?php $hasMedia = $c['images'] || $c['pdfs']; ?>
    <article class="ann-card<?= $c['pinned'] ? ' is-pinned' : '' ?>" data-ann-id="<?= $c['id'] ?>" role="button" tabindex="0" aria-label="Read announcement: <?= e($c['title']) ?>">
      <?php if ($c['images']): ?>
        <div class="ann-thumb">
          <img src="<?= e($c['images'][0]) ?>" alt="" loading="lazy" onerror="this.parentNode.remove()">
          <?php if (count($c['images']) > 1): ?><span class="ann-count">🖼 <?= count($c['images']) ?></span><?php endif; ?>
        </div>
      <?php elseif ($c['pdfs']): ?>
        <div class="ann-thumb ann-thumb-pdf"><span>📄 Poster (PDF)</span></div>
      <?php endif; ?>
      <div class="ann-body">
        <div class="ann-badges">
          <span class="ann-cat"><?= e($c['category']) ?></span>
          <?php if ($c['pinned']): ?><span class="badge badge-pending">📌 Pinned</span><?php endif; ?>
          <?php if ($c['upcoming']): ?><span class="badge badge-regular">Upcoming</span><?php endif; ?>
        </div>
        <h3 class="ann-title"><?= e($c['title']) ?></h3>
        <span class="ann-date"><?= e($c['posted']) ?></span>
        <p class="ann-excerpt<?= $hasMedia ? '' : ' ann-excerpt-long' ?>"><?= e($c['excerpt']) ?></p>
        <span class="ann-more">Read More →</span>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<dialog class="modal modal-lg ann-modal" id="annModal" aria-labelledby="annModalTitle">
  <div class="modal-head">
    <h3 id="annModalHeading">Announcement</h3>
    <button type="button" class="modal-close" id="annModalX" aria-label="Close">✕</button>
  </div>
  <div class="modal-body">
    <div class="ann-badges" id="annModalBadges"></div>
    <h2 class="ann-modal-title" id="annModalTitle"></h2>
    <div class="ann-modal-meta" id="annModalMeta"></div>
    <div class="ann-modal-image" id="annModalImage"></div>
    <div class="ann-modal-content" id="annModalContent"></div>
    <div id="annModalExtra" style="margin-top:14px;"></div>
    <div class="ann-modal-actions"><button type="button" class="btn btn-outline" id="annModalClose">Close</button></div>
  </div>
</dialog>

<dialog class="ann-lightbox" id="annLightbox" aria-label="Poster viewer">
  <button type="button" class="ann-lb-close" id="annLbClose" aria-label="Close viewer">✕</button>
  <button type="button" class="ann-lb-nav ann-lb-prev" id="annLbPrev" aria-label="Previous image">‹</button>
  <img id="annLbImg" src="" alt="">
  <button type="button" class="ann-lb-nav ann-lb-next" id="annLbNext" aria-label="Next image">›</button>
  <div class="ann-lb-count" id="annLbCount"></div>
</dialog>

<script type="application/json" id="annData"><?= json_encode($annPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script>
(function () {
  var data = {};
  try { data = JSON.parse(document.getElementById('annData').textContent); } catch (e) {}
  var donorModalId = <?= json_encode($donorModalId) ?>;
  var modal = document.getElementById('annModal');
  if (!modal) return;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  /* ---------- Lightbox: full-size view of one poster, with prev/next ---------- */
  var lightbox = document.getElementById('annLightbox');
  var lbImg = document.getElementById('annLbImg');
  var lbCount = document.getElementById('annLbCount');
  var lbPrev = document.getElementById('annLbPrev');
  var lbNext = document.getElementById('annLbNext');
  var lbList = [];
  var lbIndex = 0;

  function showLightboxImage() {
    lbImg.src = lbList[lbIndex];
    var multi = lbList.length > 1;
    lbCount.textContent = (lbIndex + 1) + ' / ' + lbList.length;
    lbCount.style.display = multi ? '' : 'none';
    lbPrev.style.display = multi ? '' : 'none';
    lbNext.style.display = multi ? '' : 'none';
  }

  function openLightbox(list, index) {
    lbList = list;
    lbIndex = index;
    showLightboxImage();
    lightbox.showModal();
  }

  function stepLightbox(step) {
    lbIndex = (lbIndex + step + lbList.length) % lbList.length;
    showLightboxImage();
  }

  document.getElementById('annLbClose').addEventListener('click', function () { lightbox.close(); });
  lbPrev.addEventListener('click', function () { stepLightbox(-1); });
  lbNext.addEventListener('click', function () { stepLightbox(1); });
  // Clicking outside the picture (the dimmed area) closes the viewer.
  lightbox.addEventListener('click', function (e) { if (e.target === lightbox) lightbox.close(); });
  document.addEventListener('keydown', function (e) {
    if (!lightbox.open) return;
    if (e.key === 'ArrowLeft') stepLightbox(-1);
    if (e.key === 'ArrowRight') stepLightbox(1);
  });

  /* ---------- Announcement details modal ---------- */
  function open(id) {
    var a = data[id];
    if (!a) return;
    var badges = document.getElementById('annModalBadges');
    badges.innerHTML = '';
    badges.appendChild(el('span', 'ann-cat', a.category));
    if (a.pinned) badges.appendChild(el('span', 'badge badge-pending', '📌 Pinned'));
    if (a.upcoming) badges.appendChild(el('span', 'badge badge-regular', 'Upcoming'));

    document.getElementById('annModalTitle').textContent = a.title;
    document.getElementById('annModalMeta').textContent = 'Posted ' + a.posted + (a.period ? '  ·  ' + a.period : '');

    var imgBox = document.getElementById('annModalImage');
    imgBox.innerHTML = '';
    var photos = a.images || [];
    if (photos.length) {
      var gallery = el('div', 'ann-gallery' + (photos.length === 1 ? ' is-single' : ''));
      photos.forEach(function (src, i) {
        var btn = el('button', 'ann-gallery-item');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'View image ' + (i + 1) + ' of ' + photos.length + ' full size');
        var img = el('img');
        img.src = src;
        img.alt = a.title + ' (' + (i + 1) + ' of ' + photos.length + ')';
        img.loading = 'lazy';
        btn.appendChild(img);
        btn.addEventListener('click', function () { openLightbox(photos, i); });
        gallery.appendChild(btn);
      });
      imgBox.appendChild(gallery);
    }
    (a.pdfs || []).forEach(function (href, i) {
      var label = '📄 Open the poster (PDF)' + (a.pdfs.length > 1 ? ' ' + (i + 1) : '');
      var link = el('a', 'btn btn-outline btn-sm', label);
      link.href = href; link.target = '_blank'; link.rel = 'noopener';
      imgBox.appendChild(link);
    });

    document.getElementById('annModalContent').textContent = a.content;

    var extra = document.getElementById('annModalExtra');
    extra.innerHTML = '';
    if (a.donor && donorModalId) {
      var btn = el('button', 'btn btn-primary btn-sm', '🤲 View this week’s donor list');
      btn.type = 'button';
      btn.addEventListener('click', function () {
        modal.close();
        var d = document.getElementById(donorModalId);
        if (d) d.showModal();
      });
      extra.appendChild(btn);
    }

    modal.querySelector('.modal-body').scrollTop = 0;
    modal.showModal();
  }

  document.querySelectorAll('.ann-card').forEach(function (card) {
    card.addEventListener('click', function () { open(card.getAttribute('data-ann-id')); });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(card.getAttribute('data-ann-id')); }
    });
  });
  document.getElementById('annModalX').addEventListener('click', function () { modal.close(); });
  document.getElementById('annModalClose').addEventListener('click', function () { modal.close(); });
  // Clicking the dimmed backdrop closes too.
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.close(); });
})();
</script>
