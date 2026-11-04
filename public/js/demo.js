document.addEventListener('DOMContentLoaded', function() {
    // Disable mailto links
    document.querySelectorAll('a[href^="mailto:"]').forEach(function(link) {
        link.addEventListener('click', function(event) {
            event.preventDefault();
        });
    });
// Disable tel links
    document.querySelectorAll('a[href^="tel:"]').forEach(function(link) {
        link.addEventListener('click', function(event) {
            event.preventDefault();
        });
    });
    replaceEmailsAndPhoneNumbersWithAsterisks(document.getElementById('demo'));

});
function replaceEmailsAndPhoneNumbersWithAsterisks(element) {
    if (element?.nodeType === 3) { // Text node
        // Use regular expressions to match email and phone number patterns
        element.nodeValue = element.nodeValue
            .replace(/\b([A-Za-z0-9._%+-])[^@]*@([A-Za-z0-9.-]+)\.([A-Z|a-z]{2,})\b/g, function (match, firstLetter, domain, tld) {
                var remainingAsterisks = '*'.repeat(Math.min(10, match.length - 1));
                return firstLetter + remainingAsterisks + '@' + domain + '.' + tld;
            })
            .replace(/\b(?:\+\d{1,3}\s?)?\d{1,4}[-.\s]?\d{5,}\b/g, function (match) {
                if (match.length >= 10) {
                    var firstChar = match.charAt(0) === '+' ? '+' : match.charAt(0);
                    var remainingAsterisks = '*'.repeat(Math.min(10, match.length - (firstChar === '+' ? 2 : 1)));
                    return firstChar + remainingAsterisks;
                } else {
                    return match;  // Don't replace if the length is less than 10
                }
            });
    } else if (element?.nodeType === 1) { // Element node
        for (var i = 0; i < element.childNodes.length; i++) {
            replaceEmailsAndPhoneNumbersWithAsterisks(element.childNodes[i]);
        }
    }
}
