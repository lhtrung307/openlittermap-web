<template>
    <div class="global-map-container">
        <loading v-if="loading" :active.sync="loading" :is-full-page="true" />

        <supercluster v-else />
    </div>
</template>

<script>
import Loading from 'vue-loading-overlay'
import 'vue-loading-overlay/dist/vue-loading.css'
import Supercluster from './Supercluster'

export default {
    name: 'GlobalMapContainer',
    components: { Loading, Supercluster },
    async created ()
    {
        // this.$store.dispatch('GLOBAL_MAP_DATA', 'one-month'); // today, one-week
        await this.$store.dispatch('GET_CLUSTERS', 2);
    },
    computed: {
        /**
         * Show loading when changing dates
         */
        loading ()
        {
            return this.$store.state.globalmap.loading;
        }
    }
}
</script>

<style scoped>
    @import '~leaflet/dist/leaflet.css';

    .global-map-container {
        height: calc(100% - 72px);
        margin: 0;
        position: relative;
        z-index: 1;
    }
</style>
