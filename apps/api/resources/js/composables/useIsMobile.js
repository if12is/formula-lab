import { onBeforeUnmount, onMounted, ref } from 'vue';

/** True below the md breakpoint (768px), live: the explain panel is a bottom sheet there and a side drawer above. */
export function useIsMobile() {
    const isMobile = ref(false);
    let query = null;
    const update = () => { isMobile.value = query.matches; };

    onMounted(() => {
        query = window.matchMedia('(max-width: 767px)');
        update();
        query.addEventListener('change', update);
    });
    onBeforeUnmount(() => query?.removeEventListener('change', update));

    return isMobile;
}
