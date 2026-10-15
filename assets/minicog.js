// 管理画面の補助スクリプト

// <time data-local> をブラウザのタイムゾーンで表示する（サーバーは UTC で出力する）
function minicogLocalizeTimes(root) {
  root.querySelectorAll('time[data-local]').forEach(function (el) {
    var date = new Date(el.getAttribute('datetime'));
    if (isNaN(date)) return;
    var options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' };
    if (el.dataset.local === 'seconds') options.second = '2-digit';
    el.textContent = date.toLocaleString(undefined, options);
    el.title = el.getAttribute('datetime');
  });
}

document.addEventListener('DOMContentLoaded', function () { minicogLocalizeTimes(document); });
document.addEventListener('htmx:load', function (event) { minicogLocalizeTimes(event.detail.elt); });
