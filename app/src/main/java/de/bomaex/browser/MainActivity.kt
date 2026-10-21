package de.bomaex.browser

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.app.DownloadManager
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.os.Message
import android.provider.Settings
import android.text.InputType
import android.util.Base64
import android.view.Gravity
import android.view.KeyEvent
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.EditorInfo
import android.view.inputmethod.InputMethodManager
import android.webkit.CookieManager
import android.webkit.GeolocationPermissions
import android.webkit.PermissionRequest
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebStorage
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import kotlin.concurrent.thread

class Tab(val web: WebView) {
    var title: String = "Neuer Tab"
    var url: String = ""
    var home: Boolean = false
    var desktop: Boolean = false
    var mobileUa: String = ""
    var desktopUa: String = ""
}

class MainActivity : Activity() {

    private val BASE = "https://web.bomaex.de"
    private val SEARCH = "https://www.google.com/search?q="
    private val BG = Color.parseColor("#0b1424")
    private val BAR = Color.parseColor("#0f1b30")
    private val CARD = Color.parseColor("#1c2a42")
    private val TX = Color.parseColor("#f1f6ff")
    private val MUT = Color.parseColor("#8ea3c4")

    private val tabs = ArrayList<Tab>()
    private var cur = -1
    private lateinit var content: FrameLayout
    private lateinit var urlBar: EditText
    private lateinit var progress: ProgressBar
    private lateinit var tabBtn: Button
    private lateinit var prefs: android.content.SharedPreferences

    private var fav = JSONArray()
    private var hist = JSONArray()
    private var cfgLinks = JSONArray()

    private var sid = ""
    private var meEmail: String? = null
    private var meRole = ""
    private var meAi = false
    private var syncTheme = "dark"
    private var syncProxy = true
    private var syncExt = false

    private var customView: View? = null
    private var customCb: WebChromeClient.CustomViewCallback? = null
    private var fileCb: ValueCallback<Array<Uri>>? = null
    private var pendingMedia: PermissionRequest? = null
    private var geoCb: GeolocationPermissions.Callback? = null
    private var geoOrigin = ""

    private val ui = Handler(Looper.getMainLooper())
    private var pushR: Runnable? = null

    private fun dp(v: Int): Int = (v * resources.displayMetrics.density).toInt()
    private fun toast(t: String) { Toast.makeText(this, t, Toast.LENGTH_SHORT).show() }

    // ------------------------------------------------------------------ Start

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = getSharedPreferences("bm", Context.MODE_PRIVATE)
        try { fav = JSONArray(prefs.getString("fav", "[]")) } catch (e: Exception) { fav = JSONArray() }
        try { hist = JSONArray(prefs.getString("hist", "[]")) } catch (e: Exception) { hist = JSONArray() }
        try { cfgLinks = JSONArray(prefs.getString("cfg", "[]")) } catch (e: Exception) { cfgLinks = JSONArray() }
        sid = prefs.getString("sid", "") ?: ""

        window.statusBarColor = BAR
        window.navigationBarColor = BAR
        CookieManager.getInstance().setAcceptCookie(true)

        buildUi()

        if (sid.isNotEmpty()) { applySessionCookie(); refreshMe() }
        if (!handleIntent(intent)) newTab(null)
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        if (intent != null) { setIntent(intent); handleIntent(intent) }
    }

    private fun handleIntent(i: Intent): Boolean {
        val d = i.data
        if (i.action == Intent.ACTION_VIEW && d != null) {
            val s = d.scheme ?: ""
            if (s == "http" || s == "https") { newTab(d.toString()); return true }
        }
        return false
    }

    @SuppressLint("SetTextI18n")
    private fun buildUi() {
        val root = LinearLayout(this)
        root.orientation = LinearLayout.VERTICAL
        root.setBackgroundColor(BG)

        val bar = LinearLayout(this)
        bar.orientation = LinearLayout.HORIZONTAL
        bar.gravity = Gravity.CENTER_VERTICAL
        bar.setBackgroundColor(BAR)
        bar.setPadding(dp(6), dp(6), dp(6), dp(6))

        fun iconBtn(label: String, desc: String, action: () -> Unit): Button {
            val b = Button(this)
            b.text = label
            b.contentDescription = desc
            b.isAllCaps = false
            b.setTextColor(TX)
            b.textSize = 22f
            b.setBackgroundColor(Color.TRANSPARENT)
            b.minWidth = 0
            b.minimumWidth = dp(40)
            b.minHeight = 0
            b.minimumHeight = dp(40)
            b.setPadding(0, 0, 0, 0)
            b.setOnClickListener { action() }
            return b
        }

        bar.addView(iconBtn("\u2039", "Zurück") { goBack() }, LinearLayout.LayoutParams(dp(40), dp(44)))

        urlBar = EditText(this)
        urlBar.hint = "Suchen oder Adresse eingeben"
        urlBar.setHintTextColor(MUT)
        urlBar.setTextColor(TX)
        urlBar.textSize = 15f
        urlBar.setSingleLine(true)
        urlBar.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
        urlBar.imeOptions = EditorInfo.IME_ACTION_GO
        urlBar.setPadding(dp(14), 0, dp(14), 0)
        val urlBg = GradientDrawable()
        urlBg.setColor(CARD)
        urlBg.cornerRadius = dp(22).toFloat()
        urlBar.background = urlBg
        urlBar.setOnEditorActionListener { v, actionId, ev ->
            val enter = ev != null && ev.keyCode == KeyEvent.KEYCODE_ENTER && ev.action == KeyEvent.ACTION_DOWN
            if (actionId == EditorInfo.IME_ACTION_GO || enter) {
                go(v.text.toString())
                hideKeyboard()
                true
            } else false
        }
        urlBar.setOnFocusChangeListener { v, hasFocus ->
            if (hasFocus) v.post { (v as EditText).selectAll() }
        }
        bar.addView(urlBar, LinearLayout.LayoutParams(0, dp(44), 1f))

        bar.addView(iconBtn("\u21BB", "Neu laden") { reload() }, LinearLayout.LayoutParams(dp(40), dp(44)))

        tabBtn = Button(this)
        tabBtn.text = "1"
        tabBtn.isAllCaps = false
        tabBtn.setTextColor(TX)
        tabBtn.textSize = 13f
        tabBtn.minWidth = 0
        tabBtn.minimumWidth = 0
        tabBtn.minHeight = 0
        tabBtn.minimumHeight = 0
        tabBtn.setPadding(0, 0, 0, 0)
        val tabBg = GradientDrawable()
        tabBg.setColor(Color.TRANSPARENT)
        tabBg.setStroke(dp(2), TX)
        tabBg.cornerRadius = dp(6).toFloat()
        tabBtn.background = tabBg
        tabBtn.contentDescription = "Tabs"
        tabBtn.setOnClickListener { tabsDialog() }
        val tlp = LinearLayout.LayoutParams(dp(28), dp(28))
        tlp.setMargins(dp(8), 0, dp(8), 0)
        bar.addView(tabBtn, tlp)

        bar.addView(iconBtn("\u22EE", "Menü") { menu() }, LinearLayout.LayoutParams(dp(36), dp(44)))

        root.addView(bar, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT))

        progress = ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal)
        progress.max = 100
        progress.visibility = View.GONE
        root.addView(progress, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(3)))

        content = FrameLayout(this)
        root.addView(content, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f))

        setContentView(root)
    }

    // ------------------------------------------------------------------ Tabs

    private fun cur(): Tab = tabs[cur]
    private fun tabFor(w: WebView): Tab? = tabs.firstOrNull { it.web === w }
    private fun isCur(w: WebView): Boolean = cur >= 0 && cur < tabs.size && tabs[cur].web === w

    private fun newTab(url: String?, popup: Boolean = false): Tab {
        val w = makeWebView()
        val t = Tab(w)
        val s = w.settings
        t.mobileUa = s.userAgentString
        t.desktopUa = desktopUaFrom(t.mobileUa)
        tabs.add(t)
        select(tabs.size - 1)
        if (!popup) {
            if (url == null) showHome(t) else w.loadUrl(url)
        }
        return t
    }

    private fun select(i: Int) {
        if (i < 0 || i >= tabs.size) return
        cur = i
        content.removeAllViews()
        val w = tabs[i].web
        (w.parent as? ViewGroup)?.removeView(w)
        content.addView(w, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
        w.requestFocus()
        progress.visibility = View.GONE
        showUrl(tabs[i].url)
        tabBtn.text = tabs.size.toString()
    }

    private fun closeTab(i: Int) {
        if (i < 0 || i >= tabs.size) return
        val t = tabs.removeAt(i)
        content.removeView(t.web)
        t.web.stopLoading()
        t.web.destroy()
        if (tabs.isEmpty()) {
            cur = -1
            newTab(null)
            return
        }
        val newCur = when {
            i < cur -> cur - 1
            i == cur -> minOf(cur, tabs.size - 1)
            else -> cur
        }
        select(newCur)
    }

    private fun tabsDialog() {
        val items = ArrayList<String>()
        for (i in tabs.indices) {
            val t = tabs[i]
            val host = try { Uri.parse(t.url).host ?: "" } catch (e: Exception) { "" }
            items.add((if (i == cur) "\u25CF " else "") + t.title + (if (host.isNotEmpty()) "\n" + host else ""))
        }
        AlertDialog.Builder(this)
            .setTitle("Tabs (" + tabs.size + ")")
            .setItems(items.toTypedArray()) { _, which -> select(which) }
            .setPositiveButton("Neuer Tab") { _, _ -> newTab(null) }
            .setNeutralButton("Tab schließen") { _, _ -> closeTab(cur) }
            .setNegativeButton("Zurück", null)
            .show()
    }

    // ------------------------------------------------------------------ WebView

    private fun desktopUaFrom(ua: String): String {
        val chrome = Regex("Chrome/[\\d.]+").find(ua)?.value ?: "Chrome/124.0.0.0"
        return "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) $chrome Safari/537.36"
    }

    private fun mobileUaFrom(ua: String): String =
        ua.replace("; wv", "").replace(Regex("Version/\\d+\\.\\d+\\s"), "")

    @SuppressLint("SetJavaScriptEnabled")
    private fun makeWebView(): WebView {
        val w = WebView(this)
        w.setBackgroundColor(BG)
        val s = w.settings
        s.javaScriptEnabled = true
        s.domStorageEnabled = true
        s.allowFileAccess = false
        s.allowContentAccess = false
        s.setSupportMultipleWindows(true)
        s.javaScriptCanOpenWindowsAutomatically = true
        s.builtInZoomControls = true
        s.displayZoomControls = false
        s.setSupportZoom(true)
        s.useWideViewPort = true
        s.loadWithOverviewMode = true
        s.mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
        s.mediaPlaybackRequiresUserGesture = false
        s.userAgentString = mobileUaFrom(s.userAgentString)
        CookieManager.getInstance().setAcceptThirdPartyCookies(w, true)
        w.webViewClient = client
        w.webChromeClient = chrome
        w.setDownloadListener { url, ua, cd, mime, _ -> download(url, ua, cd, mime) }
        w.setOnLongClickListener {
            val hr = w.hitTestResult
            val extra = hr.extra
            when (hr.type) {
                WebView.HitTestResult.SRC_ANCHOR_TYPE,
                WebView.HitTestResult.SRC_IMAGE_ANCHOR_TYPE -> {
                    if (extra != null) { linkMenu(extra, false); true } else false
                }
                WebView.HitTestResult.IMAGE_TYPE -> {
                    if (extra != null) { linkMenu(extra, true); true } else false
                }
                else -> false
            }
        }
        return w
    }

    private fun isHomeUrl(u: String): Boolean =
        u.isEmpty() || u.startsWith("bmstart:") || u.startsWith("about:") || u.startsWith("data:")

    private val client = object : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val u = request.url
            val scheme = u.scheme ?: ""
            if (scheme == "bmsearch") {
                val q = u.getQueryParameter("q") ?: ""
                if (q.isNotBlank()) view.loadUrl(resolve(q))
                return true
            }
            if (scheme == "http" || scheme == "https" || scheme == "about" || scheme == "data" || scheme == "bmstart") return false
            openExternal(u.toString(), view)
            return true
        }

        override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
            val t = tabFor(view) ?: return
            t.url = if (isHomeUrl(url)) "" else url
            t.home = isHomeUrl(url)
            if (isCur(view)) showUrl(t.url)
        }

        override fun doUpdateVisitedHistory(view: WebView, url: String, isReload: Boolean) {
            val t = tabFor(view) ?: return
            t.url = if (isHomeUrl(url)) "" else url
            t.home = isHomeUrl(url)
            if (isCur(view)) showUrl(t.url)
        }

        override fun onPageFinished(view: WebView, url: String) {
            val t = tabFor(view) ?: return
            val title = view.title ?: ""
            if (t.home) t.title = "Neuer Tab" else if (title.isNotBlank()) t.title = title else t.title = url
            if (url.startsWith("http://") || url.startsWith("https://")) addHistory(t.title, url)
            if (isCur(view)) { progress.visibility = View.GONE }
        }

        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
            if (!request.isForMainFrame) return
            val failing = request.url.toString()
            val html = "<html><head><meta name='viewport' content='width=device-width,initial-scale=1'></head>" +
                "<body style='background:#0b1424;color:#f1f6ff;font-family:sans-serif;padding:28px'>" +
                "<h2>Seite nicht erreichbar</h2><p style='color:#8ea3c4'>" + esc(error.description?.toString() ?: "Fehler") + "</p>" +
                "<p><a style='color:#5cc8ff' href='" + esc(failing) + "'>Erneut versuchen</a></p></body></html>"
            view.loadDataWithBaseURL(failing, html, "text/html", "utf-8", failing)
        }
    }

    private val chrome = object : WebChromeClient() {
        override fun onProgressChanged(view: WebView, newProgress: Int) {
            if (isCur(view)) {
                progress.progress = newProgress
                progress.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
            }
        }

        override fun onReceivedTitle(view: WebView, title: String?) {
            val t = tabFor(view) ?: return
            if (!t.home && !title.isNullOrBlank()) t.title = title
        }

        override fun onCreateWindow(view: WebView, isDialog: Boolean, isUserGesture: Boolean, resultMsg: Message): Boolean {
            if (!isUserGesture) return false
            val t = newTab(null, true)
            val transport = resultMsg.obj as WebView.WebViewTransport
            transport.webView = t.web
            resultMsg.sendToTarget()
            return true
        }

        override fun onCloseWindow(window: WebView) {
            ui.post {
                val i = tabs.indexOfFirst { it.web === window }
                if (i >= 0) closeTab(i)
            }
        }

        override fun onShowFileChooser(
            webView: WebView,
            filePathCallback: ValueCallback<Array<Uri>>,
            fileChooserParams: FileChooserParams
        ): Boolean {
            fileCb?.onReceiveValue(null)
            fileCb = filePathCallback
            return try {
                startActivityForResult(fileChooserParams.createIntent(), 1)
                true
            } catch (e: Exception) {
                fileCb = null
                false
            }
        }

        override fun onShowCustomView(view: View, callback: CustomViewCallback) {
            if (customView != null) { callback.onCustomViewHidden(); return }
            customView = view
            customCb = callback
            (window.decorView as ViewGroup).addView(
                view, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            )
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = View.SYSTEM_UI_FLAG_FULLSCREEN or
                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or
                View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
        }

        override fun onHideCustomView() { hideCustom() }

        override fun onPermissionRequest(request: PermissionRequest) {
            runOnUiThread { askMedia(request) }
        }

        override fun onGeolocationPermissionsShowPrompt(origin: String, callback: GeolocationPermissions.Callback) {
            AlertDialog.Builder(this@MainActivity)
                .setTitle("Standort")
                .setMessage("$origin möchte deinen Standort verwenden.")
                .setPositiveButton("Erlauben") { _, _ ->
                    if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED) {
                        callback.invoke(origin, true, false)
                    } else {
                        geoCb = callback
                        geoOrigin = origin
                        requestPermissions(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION), 3)
                    }
                }
                .setNegativeButton("Ablehnen") { _, _ -> callback.invoke(origin, false, false) }
                .setOnCancelListener { callback.invoke(origin, false, false) }
                .show()
        }
    }

    private fun hideCustom() {
        val v = customView ?: return
        (window.decorView as ViewGroup).removeView(v)
        customCb?.onCustomViewHidden()
        customView = null
        customCb = null
        @Suppress("DEPRECATION")
        window.decorView.systemUiVisibility = View.SYSTEM_UI_FLAG_VISIBLE
    }

    private fun askMedia(req: PermissionRequest) {
        val res = req.resources
        val names = ArrayList<String>()
        val need = ArrayList<String>()
        for (r in res) {
            if (r == PermissionRequest.RESOURCE_VIDEO_CAPTURE) { names.add("die Kamera"); need.add(Manifest.permission.CAMERA) }
            if (r == PermissionRequest.RESOURCE_AUDIO_CAPTURE) { names.add("das Mikrofon"); need.add(Manifest.permission.RECORD_AUDIO) }
        }
        if (need.isEmpty()) { req.deny(); return }
        val host = req.origin.host ?: "Die Seite"
        AlertDialog.Builder(this)
            .setTitle("Berechtigung")
            .setMessage(host + " möchte " + names.joinToString(" und ") + " verwenden.")
            .setPositiveButton("Erlauben") { _, _ ->
                val missing = need.filter { checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED }
                if (missing.isEmpty()) {
                    req.grant(res)
                } else {
                    pendingMedia = req
                    requestPermissions(missing.toTypedArray(), 2)
                }
            }
            .setNegativeButton("Ablehnen") { _, _ -> req.deny() }
            .setOnCancelListener { req.deny() }
            .show()
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        val ok = grantResults.isNotEmpty() && grantResults.all { it == PackageManager.PERMISSION_GRANTED }
        if (requestCode == 2) {
            val r = pendingMedia
            pendingMedia = null
            if (r != null) { if (ok) r.grant(r.resources) else r.deny() }
        } else if (requestCode == 3) {
            val c = geoCb
            geoCb = null
            c?.invoke(geoOrigin, ok, false)
        }
    }

    @Suppress("DEPRECATION")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == 1) {
            fileCb?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(resultCode, data))
            fileCb = null
        }
    }

    private fun download(url: String, ua: String?, cd: String?, mime: String?) {
        if (!(url.startsWith("http://") || url.startsWith("https://"))) {
            toast("Dieser Download-Typ wird noch nicht unterstützt.")
            return
        }
        if (Build.VERSION.SDK_INT < 29 && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(arrayOf(Manifest.permission.WRITE_EXTERNAL_STORAGE), 4)
            toast("Bitte Berechtigung erteilen und den Download erneut starten.")
            return
        }
        try {
            val name = URLUtil.guessFileName(url, cd, mime)
            val r = DownloadManager.Request(Uri.parse(url))
            if (!mime.isNullOrEmpty()) r.setMimeType(mime)
            val cookie = CookieManager.getInstance().getCookie(url)
            if (cookie != null) r.addRequestHeader("Cookie", cookie)
            if (ua != null) r.addRequestHeader("User-Agent", ua)
            r.setTitle(name)
            r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            r.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
            (getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(r)
            toast("Download gestartet: $name")
        } catch (e: Exception) {
            toast("Download nicht möglich.")
        }
    }

    private fun linkMenu(url: String, image: Boolean) {
        val items = if (image) arrayOf("Bild in neuem Tab", "Bild speichern", "Adresse kopieren")
        else arrayOf("In neuem Tab öffnen", "Adresse kopieren", "Teilen")
        AlertDialog.Builder(this)
            .setTitle(url.take(80))
            .setItems(items) { _, i ->
                if (image) {
                    when (i) {
                        0 -> newTab(url)
                        1 -> download(url, null, null, null)
                        else -> copy(url)
                    }
                } else {
                    when (i) {
                        0 -> newTab(url)
                        1 -> copy(url)
                        else -> shareText(url)
                    }
                }
            }
            .show()
    }

    private fun copy(t: String) {
        val cm = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        cm.setPrimaryClip(ClipData.newPlainText("Adresse", t))
        toast("Kopiert")
    }

    private fun shareText(t: String) {
        val i = Intent(Intent.ACTION_SEND)
        i.type = "text/plain"
        i.putExtra(Intent.EXTRA_TEXT, t)
        startActivity(Intent.createChooser(i, "Teilen"))
    }

    private fun openExternal(url: String, view: WebView?) {
        try {
            val i = if (url.startsWith("intent:")) Intent.parseUri(url, Intent.URI_INTENT_SCHEME)
            else Intent(Intent.ACTION_VIEW, Uri.parse(url))
            i.addCategory(Intent.CATEGORY_BROWSABLE)
            i.component = null
            i.selector = null
            startActivity(i)
        } catch (e: Exception) {
            var fb: String? = null
            if (url.startsWith("intent:")) {
                val m = Regex("S\\.browser_fallback_url=([^;]+)").find(url)
                if (m != null) fb = Uri.decode(m.groupValues[1])
            }
            if (fb != null && (fb.startsWith("http://") || fb.startsWith("https://")) && view != null) view.loadUrl(fb)
            else toast("Keine passende App gefunden.")
        }
    }

    // ------------------------------------------------------------------ Navigation

    private fun resolve(v: String): String {
        val t = v.trim()
        if (t.isEmpty()) return ""
        if (t.startsWith("http://") || t.startsWith("https://")) return t
        if (t.matches(Regex("^[^\\s/]+\\.[a-zA-Z]{2,}(:\\d+)?([/?#].*)?$"))) return "https://$t"
        return SEARCH + URLEncoder.encode(t, "UTF-8")
    }

    private fun go(input: String) {
        val u = resolve(input)
        if (u.isEmpty() || tabs.isEmpty()) return
        cur().web.loadUrl(u)
    }

    private fun reload() {
        if (tabs.isEmpty()) return
        val t = cur()
        if (t.home) showHome(t) else t.web.reload()
    }

    private fun goBack() {
        if (tabs.isEmpty()) return
        val t = cur()
        if (t.web.canGoBack()) t.web.goBack() else if (!t.home) showHome(t)
    }

    private fun showUrl(u: String) {
        if (urlBar.hasFocus()) return
        urlBar.setText(u.removePrefix("https://"))
    }

    private fun hideKeyboard() {
        val imm = getSystemService(Context.INPUT_METHOD_SERVICE) as InputMethodManager
        imm.hideSoftInputFromWindow(urlBar.windowToken, 0)
        urlBar.clearFocus()
    }

    @Suppress("DEPRECATION")
    override fun onBackPressed() {
        if (customView != null) { hideCustom(); return }
        if (tabs.isEmpty()) { super.onBackPressed(); return }
        val t = cur()
        if (t.web.canGoBack()) t.web.goBack()
        else if (tabs.size > 1) closeTab(cur)
        else if (!t.home) showHome(t)
        else super.onBackPressed()
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
        if (tabs.isNotEmpty() && cur >= 0) cur().web.onPause()
    }

    override fun onResume() {
        super.onResume()
        if (tabs.isNotEmpty() && cur >= 0) cur().web.onResume()
    }

    override fun onDestroy() {
        for (t in tabs) { try { t.web.destroy() } catch (e: Exception) { } }
        tabs.clear()
        super.onDestroy()
    }

    // ------------------------------------------------------------------ Startseite

    private fun esc(s: String): String =
        s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\"", "&quot;").replace("'", "&#39;")

    private fun showHome(t: Tab) {
        t.home = true
        t.url = ""
        t.title = "Neuer Tab"
        t.web.loadDataWithBaseURL("https://bmstart.invalid/", homeHtml(), "text/html", "utf-8", "bmstart://home")
        if (cur >= 0 && cur < tabs.size && tabs[cur] === t) showUrl("")
    }

    private fun homeHtml(): String {
        var logo = ""
        try {
            val b = assets.open("logo.png").readBytes()
            logo = "data:image/png;base64," + Base64.encodeToString(b, Base64.NO_WRAP)
        } catch (e: Exception) { }
        val links = ArrayList<Pair<String, String>>()
        for (i in 0 until cfgLinks.length()) {
            val o = cfgLinks.optJSONObject(i) ?: continue
            links.add(Pair(o.optString("t"), o.optString("u")))
        }
        if (links.isEmpty()) {
            links.add(Pair("Messenger", "https://www.bo-maex.de"))
            links.add(Pair("BM Farm", "https://www.bmfarm.de"))
            links.add(Pair("Community", "https://www.bo-maex.de"))
        }
        val sb = StringBuilder()
        sb.append("<!doctype html><html><head><meta charset='utf-8'>")
        sb.append("<meta name='viewport' content='width=device-width,initial-scale=1'><style>")
        sb.append("body{margin:0;background:#0b1424;color:#f1f6ff;font:16px system-ui,Roboto,sans-serif;padding:22px 16px}")
        sb.append(".tl{color:#8ea3c4;margin:0 0 18px;font-size:15px}.tl b{color:#f1f6ff}")
        sb.append("form{display:flex;align-items:center;gap:12px;background:#22304a;border-radius:30px;padding:0 18px;height:54px}")
        sb.append("form img{width:34px;height:34px;object-fit:contain}")
        sb.append("input{flex:1;min-width:0;background:none;border:0;outline:0;color:#f1f6ff;font-size:17px;height:100%}")
        sb.append(".g{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:18px}")
        sb.append(".g a{display:block;text-align:center;text-decoration:none;color:#f1f6ff;background:#1d3856;border-radius:18px;padding:18px 6px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}")
        sb.append(".g.f a{background:#1c2a42;font-weight:600;font-size:14px}")
        sb.append("h3{margin:26px 0 0;font-size:16px}")
        sb.append("</style></head><body>")
        sb.append("<p class='tl'><b>BM Browser</b> \u00B7 Dein Internet. Deine Welt.</p>")
        sb.append("<form action='bmsearch://go' method='get'>")
        if (logo.isNotEmpty()) sb.append("<img src='").append(logo).append("' alt='BM'>")
        sb.append("<input name='q' type='text' placeholder='Suchen oder Adresse eingeben' autocomplete='off' autocapitalize='off'></form>")
        sb.append("<div class='g'>")
        for (l in links) sb.append("<a href='").append(esc(l.second)).append("'>").append(esc(l.first)).append("</a>")
        sb.append("</div>")
        if (fav.length() > 0) {
            sb.append("<h3>\u2605 Deine Favoriten</h3><div class='g f'>")
            for (i in 0 until fav.length()) {
                val o = fav.optJSONObject(i) ?: continue
                val title = o.optString("t").ifBlank { o.optString("u") }
                sb.append("<a href='").append(esc(o.optString("u"))).append("'>").append(esc(title)).append("</a>")
            }
            sb.append("</div>")
        }
        sb.append("</body></html>")
        return sb.toString()
    }

    // ------------------------------------------------------------------ Favoriten & Verlauf

    private fun saveLocal() {
        prefs.edit().putString("fav", fav.toString()).putString("hist", hist.toString()).apply()
        if (meEmail != null) pushSoon()
    }

    private fun addHistory(title: String, url: String) {
        if (hist.length() > 0 && hist.optJSONObject(0)?.optString("u") == url) return
        val n = JSONArray()
        val o = JSONObject()
        o.put("t", title)
        o.put("u", url)
        o.put("d", System.currentTimeMillis())
        n.put(o)
        for (i in 0 until minOf(hist.length(), 299)) {
            val e = hist.optJSONObject(i) ?: continue
            if (e.optString("u") != url) n.put(e)
        }
        hist = n
        saveLocal()
    }

    private fun isFavUrl(u: String): Boolean {
        for (i in 0 until fav.length()) if (fav.optJSONObject(i)?.optString("u") == u) return true
        return false
    }

    private fun toggleFav() {
        val t = cur()
        if (t.url.isEmpty()) { toast("Öffne zuerst eine Seite."); return }
        val n = JSONArray()
        var removed = false
        for (i in 0 until fav.length()) {
            val e = fav.optJSONObject(i) ?: continue
            if (e.optString("u") == t.url) removed = true else n.put(e)
        }
        if (!removed) {
            val o = JSONObject()
            o.put("t", t.title)
            o.put("u", t.url)
            n.put(o)
        }
        fav = n
        saveLocal()
        toast(if (removed) "Favorit entfernt" else "Als Favorit gespeichert")
    }

    private fun listDialog(favs: Boolean) {
        val arr = if (favs) fav else hist
        if (arr.length() == 0) { toast(if (favs) "Noch keine Favoriten." else "Verlauf ist leer."); return }
        val titles = ArrayList<String>()
        val urls = ArrayList<String>()
        for (i in 0 until minOf(arr.length(), 100)) {
            val o = arr.optJSONObject(i) ?: continue
            val u = o.optString("u")
            urls.add(u)
            titles.add(o.optString("t").ifBlank { u } + "\n" + u.removePrefix("https://"))
        }
        AlertDialog.Builder(this)
            .setTitle(if (favs) "Favoriten" else "Verlauf")
            .setItems(titles.toTypedArray()) { _, i -> cur().web.loadUrl(urls[i]) }
            .setNeutralButton("Alle löschen") { _, _ ->
                if (favs) fav = JSONArray() else hist = JSONArray()
                saveLocal()
            }
            .setNegativeButton("Zurück", null)
            .show()
    }

    private fun clearData() {
        AlertDialog.Builder(this)
            .setTitle("Verlauf & Cookies löschen?")
            .setMessage("Verlauf, Cookies und Website-Daten werden gelöscht. Favoriten bleiben erhalten.")
            .setPositiveButton("Löschen") { _, _ ->
                hist = JSONArray()
                saveLocal()
                CookieManager.getInstance().removeAllCookies(null)
                WebStorage.getInstance().deleteAllData()
                for (t in tabs) t.web.clearCache(true)
                if (sid.isNotEmpty()) applySessionCookie()
                toast("Gelöscht")
            }
            .setNegativeButton("Abbrechen", null)
            .show()
    }

    // ------------------------------------------------------------------ Menü

    private fun menu() {
        if (tabs.isEmpty()) return
        val t = cur()
        val items = ArrayList<String>()
        val acts = ArrayList<() -> Unit>()
        fun add(label: String, a: () -> Unit) { items.add(label); acts.add(a) }

        add(if (isFavUrl(t.url)) "\u2605 Favorit entfernen" else "\u2606 Als Favorit speichern") { toggleFav() }
        add("Favoriten") { listDialog(true) }
        add("Verlauf") { listDialog(false) }
        add("Neuer Tab") { newTab(null) }
        add(if (t.desktop) "Mobile Version" else "Desktop-Version") { toggleDesktop() }
        add("Seite teilen") { if (t.url.isNotEmpty()) shareText(t.url) }
        add("Konto" + (meEmail?.let { " ($it)" } ?: "")) { accountDialog() }
        if (meEmail != null) add("BM KI & Administration (Web)") { newTab("$BASE/") }
        add("Als Standardbrowser festlegen") { defaultBrowser() }
        add("Verlauf & Cookies löschen") { clearData() }
        add("Impressum") { newTab("$BASE/impressum.html") }
        add("Datenschutz") { newTab("$BASE/datenschutz.html") }

        AlertDialog.Builder(this)
            .setItems(items.toTypedArray()) { _, i -> acts[i]() }
            .show()
    }

    private fun toggleDesktop() {
        val t = cur()
        t.desktop = !t.desktop
        t.web.settings.userAgentString = if (t.desktop) t.desktopUa else mobileUaFrom(t.mobileUa)
        t.web.settings.useWideViewPort = true
        t.web.settings.loadWithOverviewMode = true
        if (t.home) showHome(t) else t.web.reload()
        toast(if (t.desktop) "Desktop-Version" else "Mobile Version")
    }

    private fun defaultBrowser() {
        try {
            startActivity(Intent(Settings.ACTION_MANAGE_DEFAULT_APPS_SETTINGS))
        } catch (e: Exception) {
            try { startActivity(Intent(Settings.ACTION_SETTINGS)) } catch (e2: Exception) { toast("Einstellungen nicht verfügbar.") }
        }
    }

    // ------------------------------------------------------------------ Konto & Synchronisation

    private fun applySessionCookie() {
        val cm = CookieManager.getInstance()
        if (sid.isEmpty()) {
            cm.setCookie(BASE, "bmsid=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax")
        } else {
            cm.setCookie(BASE, "bmsid=$sid; Path=/; Max-Age=5184000; Secure; HttpOnly; SameSite=Lax")
        }
        cm.flush()
    }

    private fun setSid(v: String) {
        sid = v
        prefs.edit().putString("sid", v).apply()
        applySessionCookie()
    }

    private fun apiCall(action: String, body: JSONObject?, get: Boolean, cb: (JSONObject) -> Unit) {
        thread {
            var out = JSONObject()
            var newSid: String? = null
            try {
                val url = URL(BASE + "/api.php" + (if (get) "?a=$action" else ""))
                val c = url.openConnection() as HttpURLConnection
                c.connectTimeout = 10000
                c.readTimeout = 15000
                c.setRequestProperty("X-BM", "1")
                if (sid.isNotEmpty()) c.setRequestProperty("Cookie", "bmsid=$sid")
                if (!get) {
                    c.requestMethod = "POST"
                    c.doOutput = true
                    c.setRequestProperty("Content-Type", "application/json")
                    val b = body ?: JSONObject()
                    b.put("a", action)
                    c.outputStream.use { it.write(b.toString().toByteArray(Charsets.UTF_8)) }
                }
                val code = c.responseCode
                val stream = if (code >= 400) c.errorStream else c.inputStream
                val txt = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() } ?: "{}"
                out = try { JSONObject(txt) } catch (e: Exception) { JSONObject().put("error", "Ungültige Serverantwort") }
                for ((k, vals) in c.headerFields) {
                    if (k != null && k.equals("set-cookie", ignoreCase = true)) {
                        for (h in vals) {
                            val m = Regex("bmsid=([a-f0-9]*)").find(h)
                            if (m != null) newSid = m.groupValues[1]
                        }
                    }
                }
            } catch (e: Exception) {
                out = JSONObject().put("error", "Keine Verbindung zum Server.")
            }
            runOnUiThread {
                val s = newSid
                if (s != null) setSid(s)
                cb(out)
            }
        }
    }

    private fun refreshMe() {
        apiCall("me", null, true) { d ->
            val cfg = d.optJSONObject("cfg")
            if (cfg != null) {
                cfgLinks = cfg.optJSONArray("links") ?: JSONArray()
                prefs.edit().putString("cfg", cfgLinks.toString()).apply()
            }
            val u = d.optJSONObject("user")
            if (u != null) {
                meEmail = u.optString("email")
                meRole = u.optString("role")
                meAi = u.optBoolean("ai")
                pullSync()
            } else if (!d.has("error")) {
                meEmail = null
                meRole = ""
                meAi = false
                if (sid.isNotEmpty()) setSid("")
            }
        }
    }

    private fun mergeArr(server: JSONArray?, local: JSONArray, cap: Int): JSONArray {
        val out = JSONArray()
        val seen = HashSet<String>()
        fun addAll(a: JSONArray?) {
            if (a == null) return
            for (i in 0 until a.length()) {
                val o = a.optJSONObject(i) ?: continue
                val u = o.optString("u")
                if (u.isNotEmpty() && out.length() < cap && seen.add(u)) out.put(o)
            }
        }
        addAll(server)
        addAll(local)
        return out
    }

    private fun pullSync() {
        apiCall("sync_get", null, false) { d ->
            val data = d.optJSONObject("data")
            if (data != null) {
                fav = mergeArr(data.optJSONArray("bm"), fav, 500)
                hist = mergeArr(data.optJSONArray("hist"), hist, 300)
                syncTheme = data.optString("theme", "dark")
                syncProxy = data.optBoolean("useproxy", true)
                syncExt = data.optBoolean("extonly", false)
                prefs.edit().putString("fav", fav.toString()).putString("hist", hist.toString()).apply()
            }
            if (!d.has("error")) pushNow()
        }
    }

    private fun pushNow() {
        if (meEmail == null) return
        val data = JSONObject()
        data.put("bm", fav)
        data.put("hist", hist)
        data.put("theme", syncTheme)
        data.put("useproxy", syncProxy)
        data.put("extonly", syncExt)
        apiCall("sync_put", JSONObject().put("data", data), false) { }
    }

    private fun pushSoon() {
        val old = pushR
        if (old != null) ui.removeCallbacks(old)
        val r = Runnable { pushNow() }
        pushR = r
        ui.postDelayed(r, 2500)
    }

    private fun accountDialog() {
        if (meEmail != null) { loggedInDialog(); return }
        val box = LinearLayout(this)
        box.orientation = LinearLayout.VERTICAL
        box.setPadding(dp(20), dp(8), dp(20), 0)
        val mail = EditText(this)
        mail.hint = "E-Mail-Adresse"
        mail.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
        val pw = EditText(this)
        pw.hint = "Passwort (mind. 8 Zeichen)"
        pw.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        val code = EditText(this)
        code.hint = "Admin-Code"
        code.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        code.visibility = View.GONE
        val msg = TextView(this)
        msg.setTextColor(Color.parseColor("#ff7b7b"))
        msg.setPadding(0, dp(8), 0, 0)
        box.addView(mail)
        box.addView(pw)
        box.addView(code)
        box.addView(msg)

        val d = AlertDialog.Builder(this)
            .setTitle("Konto (kostenlos)")
            .setView(box)
            .setPositiveButton("Anmelden", null)
            .setNegativeButton("Registrieren", null)
            .setNeutralButton("Passwort vergessen", null)
            .create()
        d.show()

        d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
            msg.text = "\u2026"
            val b = JSONObject().put("email", mail.text.toString().trim()).put("password", pw.text.toString())
            apiCall("login", b, false) { r ->
                if (r.has("error")) msg.text = r.optString("error")
                else { d.dismiss(); refreshMe(); toast("Angemeldet") }
            }
        }
        d.getButton(AlertDialog.BUTTON_NEGATIVE).setOnClickListener {
            msg.text = "\u2026"
            val b = JSONObject().put("email", mail.text.toString().trim()).put("password", pw.text.toString())
                .put("admin_code", code.text.toString())
            apiCall("register", b, false) { r ->
                if (r.optString("code") == "admin_code") code.visibility = View.VISIBLE
                if (r.has("error")) msg.text = r.optString("error")
                else { d.dismiss(); refreshMe(); toast("Konto erstellt") }
            }
        }
        d.getButton(AlertDialog.BUTTON_NEUTRAL).setOnClickListener {
            val m = mail.text.toString().trim()
            if (m.isEmpty()) { msg.text = "Bitte oben die E-Mail-Adresse eingeben." }
            else {
                msg.text = "\u2026"
                apiCall("forgot", JSONObject().put("email", m), false) { r ->
                    msg.text = if (r.has("error")) r.optString("error") else r.optString("message")
                }
            }
        }
    }

    private fun loggedInDialog() {
        val info = when {
            meRole == "admin" -> "Administrator \u00B7 BM KI freigeschaltet"
            meAi -> "BM KI ist freigeschaltet."
            else -> "BM KI ist für dieses Konto nicht freigeschaltet."
        }
        AlertDialog.Builder(this)
            .setTitle("Konto")
            .setMessage("Angemeldet als " + (meEmail ?: "") + "\n" + info +
                "\n\nFavoriten und Verlauf werden mit deinem Konto synchronisiert.")
            .setPositiveButton("Abmelden") { _, _ ->
                apiCall("logout", null, false) {
                    setSid("")
                    meEmail = null
                    meRole = ""
                    meAi = false
                    toast("Abgemeldet")
                }
            }
            .setNeutralButton("Web-Konto öffnen") { _, _ -> newTab("$BASE/") }
            .setNegativeButton("Schließen", null)
            .show()
    }
}
