<?php
/**
 * Public announcement cards + full-announcement modal, shared by the landing
 * page and the Announcements page.
 *
 * Every card has the same structure and (via the grid + line clamping) the
 * same height: thumbnail (when there is one), category + pinned badges,
 * title, date, a short excerpt, and a "Read More" footer. The complete text
 * and poster only appear in the modal — the page never navigates away.
 *
 * Expects $announcements (rows from `announcements`). Optional
 * $donorModalId: the id of a donor-list dialog on the page; the current
 * week's donor announcement then gets a "View donor list" button in its modal.
 */
$donorModalId = $donorModalId ?? null;

$annCards = [];
$annPayload = [];
foreach ($announcements as $a) {
    $image = $a['image'] ?: null;
    $isPdf = $image && strtolower(pathinfo($image, PATHINFO_EXTENSION)) === 'pdf';
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
        'image' => ($image && !$isPdf) ? documentUrl($image) : null,
        'pdf' => $isPdf ? documentUrl($image) : null,
        'donor' => $donorModalId && isCurrentWeeklyDonorAnnouncement($a['title']),
    ];
    $annCards[] = $card;
    $annPayload[$card['id']] = $card + ['content' => $a['content']];
}
?>
<div class="ann-grid">
  <?php foreach ($annCards as $c): ?>
    <article class="ann-card<?= $c['pinned'] ? ' is-pinned' : '' ?>" data-ann-id="<?= $c['id'] ?>" role="button" tabindex="0" aria-label="Read announcement: <?= e($c['title']) ?>">
      <?php if ($c['image']): ?>
        <div class="ann-thumb"><img src="<?= e($c['image']) ?>" alt="" loading="lazy" onerror="this.parentNode.remove()"></div>
      <?php elseif ($c['pdf']): ?>
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
        <p class="ann-excerpt<?= ($c['image'] || $c['pdf']) ? '' : ' ann-excerpt-long' ?>"><?= e($c['excerpt']) ?></p>
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
    if (a.image) {
      var img = el('img');
      img.src = a.image;
      img.alt = a.title;
      imgBox.appendChild(img);
    } else if (a.pdf) {
      var link = el('a', 'btn btn-outline btn-sm', '📄 Open the poster (PDF)');
      link.href = a.pdf; link.target = '_blank'; link.rel = 'noopener';
      imgBox.appendChild(link);
    }

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
