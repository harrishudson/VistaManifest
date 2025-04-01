/* Copyright (c) Harris Hudson 2025 */

var gSTATUS_REQUESTS = 0

function queue_throbber() {
 gSTATUS_REQUESTS++
 document.getElementById('throbber').style.visibility = "visible"
 return gSTATUS_REQUESTS
}

function dequeue_throbber() {
 gSTATUS_REQUESTS--
 if (gSTATUS_REQUESTS <= 0) {
  gSTATUS_REQUESTS = 0
  document.getElementById('throbber').style.visibility = "hidden"
 }
}

function status_msg(msg) {
 let status_queue = document.getElementById('status_queue')
 let el = document.createElement('li')
 el.className = "status_right"
 el.innerText = msg
 status_queue.appendChild(el)
 window.setTimeout(function () {
  el.className = "status_right status_show"
  window.setTimeout(function () { status_destroy(el) }, 5000)
 }, 80)
}

function status_destroy(el) {
 el.className = "status_right"
 window.setTimeout(function () { el.remove() }, 550)
}

function hide_page_progress() {
 let p = document.getElementById('page_progress')
 p.style.visibility = 'hidden'
 p.value = 0
 p.max = 0
 let pt = document.getElementById('page_progress_text')
 pt.textContent = ''
}

function show_page_progress(sofar, total, progress_literal) {
 let p = document.getElementById('page_progress')
 p.value = sofar
 p.max = total
 p.style.visibility = 'visible'
 let pt = document.getElementById('page_progress_text')
 pt.textContent = progress_literal
}
