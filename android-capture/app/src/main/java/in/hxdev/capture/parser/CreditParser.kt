package `in`.hxdev.capture.parser

import com.google.re2j.Pattern
import org.json.JSONObject

data class Credit(val amountMinor: Long, val reference: String?)
data class Rules(
    val version: Int, val senders: Set<String>, val packages: Map<String, Set<String>>,
    val amount: Pattern, val reference: Pattern, val credit: Pattern, val reject: Pattern
) {
    companion object {
        fun decode(json: JSONObject): Rules {
            require(json.getInt("version") > 0 && json.getString("source") == "kotak")
            val rules = json.getJSONObject("rules")
            // Existing unversioned PCRE rules are deliberately not guessed or executed.
            require(rules.getString("schema") == "hx-credit-re2-v1") { "UNSUPPORTED_PARSER_SCHEMA" }
            fun strings(key: String) = rules.getJSONArray(key).let { values -> (0 until values.length()).map { values.getString(it) }.toSet() }
            val senders = strings("sender_ids")
            require(senders.isNotEmpty() && senders.all { it.matches(Regex("^[A-Z]{2}-KOTAKB(?:-[A-Z])?$")) })
            val certificates = rules.getJSONObject("notification_signing_sha256")
            val packages = strings("notification_packages").associateWith { name ->
                require(name.matches(Regex("[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z][A-Za-z0-9_]*)+")))
                certificates.getJSONArray(name).let { a -> (0 until a.length()).map { a.getString(it) }.toSet() }.also { pins ->
                    require(pins.isNotEmpty() && pins.all { it.matches(Regex("[a-f0-9]{64}")) })
                }
            }
            require(packages.isNotEmpty())
            fun pattern(key: String, group: Boolean = false) = rules.getString(key).let { text ->
                require(text.isNotBlank() && text.length <= 500)
                Pattern.compile(text).also { require(!group || it.groupCount() == 1) }
            }
            return Rules(json.getInt("version"), senders, packages, pattern("amount_pattern", true), pattern("reference_pattern", true), pattern("credit_pattern"), pattern("reject_pattern"))
        }
    }
}

class CreditParser(private val rules: Rules) {
    private val excluded = Pattern.compile("(?i)\\b(otp|one[ -]time|verification|debited|debit|withdrawn|failed|reversed|reversal|cashback offer|eligible|pre[ -]?approved|credit card|will be credited)\\b")
    fun candidate(source: String, identity: String, text: String): Boolean {
        if (text.length !in 1..8192) return false
        if (source == "sms" && identity !in rules.senders) return false
        if (source == "notification" && identity !in rules.packages) return false
        if (source !in setOf("sms", "notification")) return false
        return !excluded.matcher(text).find() && !rules.reject.matcher(text).find() && rules.credit.matcher(text).find()
    }
    fun parse(source: String, identity: String, text: String): Credit? {
        if (!candidate(source, identity, text)) return null
        val amounts = matches(rules.amount, text).map { amount(it) ?: return null }.distinct()
        if (amounts.size != 1) return null
        val references = matches(rules.reference, text).distinct()
        if (references.size > 1 || references.any { !it.matches(Regex("[0-9]{12}")) }) return null
        return Credit(amounts.single(), references.singleOrNull())
    }
    private fun matches(pattern: Pattern, text: String): List<String> {
        val matcher = pattern.matcher(text)
        val result = mutableListOf<String>()
        while (matcher.find()) {
            result += matcher.group(1) ?: ""
            if (result.size > 16) return listOf("invalid", "ambiguous")
        }
        return result
    }
    companion object {
        fun amount(input: String): Long? {
            if (!input.matches(Regex("(?:[0-9]+|[1-9][0-9]{0,2}(?:,[0-9]{3})+|[1-9][0-9]?(?:,[0-9]{2})*,[0-9]{3})(?:\\.[0-9]{1,2})?"))) return null
            return runCatching {
                val parts = input.replace(",", "").split('.')
                Math.addExact(Math.multiplyExact(parts[0].toLong(), 100), parts.getOrElse(1) { "0" }.padEnd(2, '0').toLong()).takeIf { it > 0 }
            }.getOrNull()
        }
    }
}
