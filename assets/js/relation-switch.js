function wptsallSwitchRelation(relationId) {
	var panels = document.querySelectorAll('.wptsall-relation-panel');
	for (var i = 0; i < panels.length; i++) {
		panels[i].style.display = 'none';
	}
	var target = document.getElementById('wptsall-relation-panel-' + relationId);
	if (target) {
		target.style.display = '';
	}
}
