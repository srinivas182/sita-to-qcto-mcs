package expo.modules.codeautofill

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.os.Build
import android.util.Base64
import androidx.core.content.ContextCompat
import com.google.android.gms.auth.api.phone.SmsRetriever
import com.google.android.gms.common.api.CommonStatusCodes
import com.google.android.gms.common.api.Status
import expo.modules.kotlin.Promise
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition
import java.security.MessageDigest

/**
 * Fills in the sign-in code without typing:
 *  - WhatsApp one-tap: we tell WhatsApp (handshake) that we expect a code; when the
 *    learner taps "Autofill" on the authentication message, WhatsApp opens
 *    WhatsAppCodeActivity with the code, which passes it here.
 *  - SMS Retriever: when the code arrives by SMS ending in our app hash, Google Play
 *    services hands it to us with no SMS permission and no prompt.
 */
class CodeAutofillModule : Module() {
  private var smsReceiver: BroadcastReceiver? = null

  override fun definition() = ModuleDefinition {
    Name("CodeAutofill")
    Events("onCode")

    OnCreate { instance = this@CodeAutofillModule }
    OnDestroy {
      stopSms()
      if (instance === this@CodeAutofillModule) instance = null
    }

    /** Send the handshake to every installed WhatsApp. Returns true when one is installed. */
    Function("requestWhatsAppCode") {
      val context = appContext.reactContext ?: return@Function false
      var sent = false
      for (pkg in WHATSAPP_PACKAGES) {
        if (!installed(context, pkg)) continue
        val token = PendingIntent.getActivity(context, 0, Intent(), PendingIntent.FLAG_IMMUTABLE)
        val handshake = Intent(ACTION_REQUESTED).setPackage(pkg).putExtra(EXTRA_CALLER, token)
        context.sendBroadcast(handshake)
        sent = true
      }
      sent
    }

    /** Listen for the next SMS with our hash (up to 5 minutes). Resolves false without Play services. */
    AsyncFunction("startSmsRetriever") { promise: Promise ->
      val context = appContext.reactContext
      if (context == null) {
        promise.resolve(false)
        return@AsyncFunction
      }
      stopSms()
      SmsRetriever.getClient(context).startSmsRetriever()
        .addOnSuccessListener {
          val receiver = object : BroadcastReceiver() {
            override fun onReceive(c: Context, intent: Intent) {
              if (intent.action != SmsRetriever.SMS_RETRIEVED_ACTION) return
              val extras = intent.extras ?: return
              @Suppress("DEPRECATION")
              val status = extras.get(SmsRetriever.EXTRA_STATUS) as? Status ?: return
              if (status.statusCode != CommonStatusCodes.SUCCESS) return
              val message = extras.getString(SmsRetriever.EXTRA_SMS_MESSAGE) ?: return
              CODE.find(message)?.value?.let { deliver(it, "sms") }
              stopSms()
            }
          }
          ContextCompat.registerReceiver(
            context,
            receiver,
            IntentFilter(SmsRetriever.SMS_RETRIEVED_ACTION),
            SmsRetriever.SEND_PERMISSION,
            null,
            ContextCompat.RECEIVER_EXPORTED,
          )
          smsReceiver = receiver
          promise.resolve(true)
        }
        .addOnFailureListener { promise.resolve(false) }
    }

    Function("stop") { stopSms() }

    /** The 11-character hash SMS codes must end with (Settings → About shows it). */
    Function("appHash") {
      val context = appContext.reactContext ?: return@Function null
      appSignatureHash(context)
    }
  }

  private fun stopSms() {
    val receiver = smsReceiver ?: return
    try {
      appContext.reactContext?.unregisterReceiver(receiver)
    } catch (_: IllegalArgumentException) {
      // already unregistered
    }
    smsReceiver = null
  }

  companion object {
    const val ACTION_REQUESTED = "com.whatsapp.otp.OTP_REQUESTED"
    const val EXTRA_CALLER = "_ci_"
    val WHATSAPP_PACKAGES = listOf("com.whatsapp", "com.whatsapp.w4b")
    val CODE = Regex("\\b\\d{6}\\b")

    @Volatile var instance: CodeAutofillModule? = null

    fun deliver(code: String, source: String) {
      instance?.sendEvent("onCode", mapOf("code" to code, "source" to source))
    }

    private fun installed(context: Context, pkg: String): Boolean = try {
      context.packageManager.getPackageInfo(pkg, 0)
      true
    } catch (_: PackageManager.NameNotFoundException) {
      false
    }

    /** Google's documented app-hash: base64(sha256("<package> <signature>"))[0..11]. */
    @Suppress("DEPRECATION")
    fun appSignatureHash(context: Context): String? {
      val pm = context.packageManager
      val pkg = context.packageName
      val signature = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        pm.getPackageInfo(pkg, PackageManager.GET_SIGNING_CERTIFICATES).signingInfo?.apkContentsSigners?.firstOrNull()
      } else {
        pm.getPackageInfo(pkg, PackageManager.GET_SIGNATURES).signatures?.firstOrNull()
      } ?: return null
      val digest = MessageDigest.getInstance("SHA-256").digest("$pkg ${signature.toCharsString()}".toByteArray(Charsets.UTF_8))
      return Base64.encodeToString(digest.copyOfRange(0, 9), Base64.NO_PADDING or Base64.NO_WRAP).substring(0, 11)
    }
  }
}
